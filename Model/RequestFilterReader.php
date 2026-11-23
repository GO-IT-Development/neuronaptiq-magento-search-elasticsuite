<?php
declare(strict_types=1);

namespace NeuronaptiQ\SearchElasticsuite\Model;

use Magento\Framework\Search\Request\QueryInterface as MagentoQueryInterface;
use NeuronaptiQ\Search\Model\Search\SearchFilters;
use NeuronaptiQ\SearchElasticsuite\Exception\UnsupportedFilterException;
use Smile\ElasticsuiteCore\Search\Request\Query\Boolean;
use Smile\ElasticsuiteCore\Search\Request\Query\Filtered;
use Smile\ElasticsuiteCore\Search\Request\Query\FunctionScore;
use Smile\ElasticsuiteCore\Search\Request\Query\Nested;
use Smile\ElasticsuiteCore\Search\Request\Query\Not;
use Smile\ElasticsuiteCore\Search\Request\Query\Range;
use Smile\ElasticsuiteCore\Search\Request\Query\Term;
use Smile\ElasticsuiteCore\Search\Request\Query\Terms;
use Smile\ElasticsuiteCore\Search\RequestInterface;

/**
 * Reads the selection of the ElasticSuite layered navigation from the search request (SimpKo/project#324)
 *
 * ElasticSuite puts the selected filters into the filter of the query (count and coverage requests) or, for the
 * facets with multiselect, into the post filter of the request (page request). Both are read:
 * - option_text_<code> (terms with option texts) → group <code> with the option ids of the texts
 * - <code> of a Yes/No attribute (terms with true/false) → group <code> with 1/0
 * - nested category, category.category_id → group category, applied to the hits of the search like the other
 *   groups, so the result matches the count of the category facet (SimpKo/project#360)
 * - nested price, range on price.price → price filter, lt is an exclusive maximum
 *
 * Exclusions (must_not, not) like the blacklist of the search term, the visibility and the stock status are handled
 * by the search itself and ignored. Any other filter (e.g. virtual categories, decimal attributes, custom filters)
 * cannot be applied by the search API: UnsupportedFilterException, ElasticSuite answers the request.
 */
class RequestFilterReader
{
    private const IGNORED_FIELDS = ['visibility', 'stock.is_in_stock', 'price.customer_group_id'];
    private const IGNORED_NESTED_PATHS = ['search_query'];
    private const NESTED_PATHS = ['category', 'price'];
    private const FIELD_CATEGORY = 'category.category_id';
    private const FIELD_PRICE = 'price.price';
    private const UNTOUCHED_SUFFIX = '.untouched';

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
     * Returns the selection of the request (without facet request)
     *
     * @param RequestInterface $request
     * @return SearchFilters
     * @throws UnsupportedFilterException
     */
    public function read(RequestInterface $request): SearchFilters
    {
        $selection = new SearchFilters();
        $price = [];

        $query = $request->getQuery();
        // The query of the request: the fulltext query, filtered by the filters of the collection
        while ($query instanceof FunctionScore) {
            $query = $query->getQuery();
        }
        if ($query instanceof Filtered && $query->getFilter() !== null) {
            $selection = $this->readFilter($query->getFilter(), $selection, $price);
        }

        $filter = $request->getFilter();
        if ($filter instanceof MagentoQueryInterface) {
            $selection = $this->readFilter($filter, $selection, $price);
        }

        if ($price !== []) {
            $selection = $selection->withPrice(
                $price['min'] ?? null,
                $price['max'] ?? null,
                $price['exclusive'] ?? false
            );
        }

        return $selection;
    }

    /**
     * Adds the filters of a query to the selection
     *
     * @param MagentoQueryInterface $query
     * @param SearchFilters $selection
     * @param array $price Collected price bounds (min, max, exclusive)
     * @return SearchFilters
     * @phpstan-param array{min?: float, max?: float, exclusive?: bool} $price
     * @throws UnsupportedFilterException
     */
    private function readFilter(MagentoQueryInterface $query, SearchFilters $selection, array &$price): SearchFilters
    {
        if ($query instanceof Not) {
            return $selection;
        }

        if ($query instanceof Boolean) {
            if (!empty($query->getShould())) {
                throw new UnsupportedFilterException('Filter with alternatives (should)');
            }
            foreach ($query->getMust() as $clause) {
                $selection = $this->readFilter($clause, $selection, $price);
            }

            return $selection;
        }

        if ($query instanceof Filtered) {
            foreach ([$query->getQuery(), $query->getFilter()] as $part) {
                if ($part instanceof MagentoQueryInterface) {
                    $selection = $this->readFilter($part, $selection, $price);
                }
            }

            return $selection;
        }

        if ($query instanceof Nested) {
            $path = (string)$query->getPath();
            if (in_array($path, self::IGNORED_NESTED_PATHS, true)) {
                return $selection;
            }
            if (!in_array($path, self::NESTED_PATHS, true)) {
                throw new UnsupportedFilterException(sprintf('Nested filter on %s', $path));
            }

            return $this->readFilter($query->getQuery(), $selection, $price);
        }

        if ($query instanceof Range) {
            if ($query->getField() !== self::FIELD_PRICE) {
                throw new UnsupportedFilterException(sprintf('Range filter on %s', (string)$query->getField()));
            }
            $this->readPriceBounds($query->getBounds(), $price);

            return $selection;
        }

        if ($query instanceof Term) {
            $values = $query instanceof Terms ? (array)$query->getValues() : [$query->getValue()];

            return $this->readTerms((string)$query->getField(), $values, $selection);
        }

        throw new UnsupportedFilterException(sprintf('Filter of type %s', (string)$query->getType()));
    }

    /**
     * Adds a terms filter to the selection
     *
     * @param string $field
     * @param array $values
     * @param SearchFilters $selection
     * @return SearchFilters
     * @phpstan-param array<mixed> $values
     * @throws UnsupportedFilterException
     */
    private function readTerms(string $field, array $values, SearchFilters $selection): SearchFilters
    {
        if (str_ends_with($field, self::UNTOUCHED_SUFFIX)) {
            $field = substr($field, 0, -strlen(self::UNTOUCHED_SUFFIX));
        }

        if (in_array($field, self::IGNORED_FIELDS, true)) {
            return $selection;
        }

        if ($field === self::FIELD_CATEGORY) {
            return $selection->withCategories($values);
        }

        if (str_starts_with($field, AttributeOptions::OPTION_TEXT_PREFIX)) {
            $attributeCode = substr($field, strlen(AttributeOptions::OPTION_TEXT_PREFIX));
            $optionIds = $this->attributeOptions->usesOptionText($attributeCode)
                ? $this->attributeOptions->getOptionIds($attributeCode, $values)
                : null;
            if ($optionIds === null) {
                throw new UnsupportedFilterException(sprintf('Unknown options of %s', $attributeCode));
            }

            return $selection->withGroup($attributeCode, $optionIds);
        }

        if ($this->attributeOptions->isBoolean($field)) {
            $flags = [];
            foreach ($values as $value) {
                $flags[] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            }

            return $selection->withGroup($field, $flags);
        }

        throw new UnsupportedFilterException(sprintf('Filter on %s', $field));
    }

    /**
     * Collects the bounds of the price filter (gte/gt minimum, lt exclusive and lte inclusive maximum)
     *
     * @param array $bounds
     * @param array $price
     * @return void
     * @phpstan-param array<mixed> $bounds
     * @phpstan-param array{min?: float, max?: float, exclusive?: bool} $price
     */
    private function readPriceBounds(array $bounds, array &$price): void
    {
        foreach ($bounds as $operator => $value) {
            if (!is_numeric($value)) {
                continue;
            }
            switch ($operator) {
                case 'gte':
                case 'gt':
                    $price['min'] = (float)$value;
                    break;
                case 'lt':
                    $price['max'] = (float)$value;
                    $price['exclusive'] = true;
                    break;
                case 'lte':
                    $price['max'] = (float)$value;
                    $price['exclusive'] = false;
                    break;
            }
        }
    }
}
