<?php
declare(strict_types=1);

namespace NeuronaptiQ\SearchElasticsuite\Model;

/**
 * Converts the facets of the search API into the aggregations of an Elasticsearch response for the ElasticSuite
 * layered navigation (SimpKo/project#324)
 *
 * - attributes with options: bucket option_text_<code>, keyed by the option text (options with the same text are
 *   added up), Yes/No attributes: bucket <code>, keyed by 1/0
 * - price: bucket price.price, the ranges as histogram (key = start of the range)
 * - category: bucket categories, keyed by the category id
 * - indexed_attributes: products per attribute, ElasticSuite hides attributes below their minimum coverage
 *   (facet_min_coverage_rate). Estimated from the facet counts, at most the total (empty without hits)
 *
 * Facets of unknown attributes are skipped. Without facets (old search service) the layered navigation stays empty.
 *
 * @phpstan-import-type Facet from \NeuronaptiQ\Search\Api\FacetedSearchApiClientInterface
 * @phpstan-type RawBucket array{key: int|float|string, doc_count: int}
 * @phpstan-type RawAggregation array{buckets: list<RawBucket>}
 */
class AggregationsBuilder
{
    private const GROUP_PRICE = 'price';
    private const GROUP_CATEGORY = 'category';
    private const BUCKET_PRICE = 'price.price';
    private const BUCKET_CATEGORY = 'categories';
    private const BUCKET_INDEXED_ATTRIBUTES = 'indexed_attributes';

    /**
     * @var AttributeOptions
     */
    private AttributeOptions $attributeOptions;

    /**
     * @param AttributeOptions $attributeOptions
     */
    public function __construct(AttributeOptions $attributeOptions)
    {
        $this->attributeOptions = $attributeOptions;
    }

    /**
     * Builds the aggregations from the facets
     *
     * @param array $facets
     * @param int $total
     * @return array<string, array>
     * @phpstan-param list<Facet> $facets
     * @phpstan-return array<string, RawAggregation>
     */
    public function build(array $facets, int $total): array
    {
        $aggregations = [];
        $coverage = [];

        foreach ($facets as $facet) {
            $group = $facet['group'];
            if ($group === self::GROUP_PRICE) {
                $buckets = $this->buildPriceBuckets($facet);
                $aggregations[self::BUCKET_PRICE] = ['buckets' => $buckets];
                $coverage[$group] = array_sum(array_column($buckets, 'doc_count'));
                continue;
            }

            if ($group === self::GROUP_CATEGORY) {
                $aggregations[self::BUCKET_CATEGORY] = ['buckets' => $this->buildValueBuckets($facet, null)];
                continue;
            }

            $field = $this->attributeOptions->getFilterField($group);
            if ($field === null) {
                continue;
            }
            $optionTexts = $this->attributeOptions->usesOptionText($group)
                ? $this->attributeOptions->getOptionTexts($group)
                : null;
            $buckets = $this->buildValueBuckets($facet, $optionTexts);
            $aggregations[$field] = ['buckets' => $buckets];
            $coverage[$group] = array_sum(array_column($buckets, 'doc_count'));
        }

        $indexedAttributes = [];
        foreach ($coverage as $attributeCode => $count) {
            // Without hits it stays empty like in Elasticsearch (ElasticSuite divides by the total otherwise)
            $count = min($count, $total);
            if ($count > 0) {
                $indexedAttributes[] = ['key' => $attributeCode, 'doc_count' => $count];
            }
        }
        $aggregations[self::BUCKET_INDEXED_ATTRIBUTES] = ['buckets' => $indexedAttributes];

        return $aggregations;
    }

    /**
     * Buckets of the facet values, keyed by the option text if given (unknown options are skipped)
     *
     * @param array $facet
     * @param array|null $optionTexts
     * @return list<array>
     * @phpstan-param Facet $facet
     * @phpstan-param array<string, string>|null $optionTexts
     * @phpstan-return list<RawBucket>
     */
    private function buildValueBuckets(array $facet, ?array $optionTexts): array
    {
        $counts = [];
        foreach ($facet['values'] as $value) {
            $key = (string)$value['value'];
            if ($optionTexts !== null) {
                if (!isset($optionTexts[$key])) {
                    continue;
                }
                $key = $optionTexts[$key];
            }
            $counts[$key] = ($counts[$key] ?? 0) + (int)$value['count'];
        }

        $buckets = [];
        foreach ($counts as $key => $count) {
            if ($count > 0) {
                $buckets[] = ['key' => $key, 'doc_count' => $count];
            }
        }

        return $buckets;
    }

    /**
     * Histogram buckets of the price ranges, the key is the start of the range
     *
     * @param array $facet
     * @return list<array>
     * @phpstan-param Facet $facet
     * @phpstan-return list<RawBucket>
     */
    private function buildPriceBuckets(array $facet): array
    {
        $buckets = [];
        foreach ($facet['ranges'] ?? [] as $range) {
            if ($range['count'] > 0) {
                $from = (float)$range['from'];
                $key = floor($from) === $from ? (int)$from : $from;
                $buckets[] = ['key' => $key, 'doc_count' => (int)$range['count']];
            }
        }

        return $buckets;
    }
}
