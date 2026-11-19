<?php
declare(strict_types=1);

namespace NeuronaptiQ\SearchElasticsuite\Model;

use Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Response\QueryResponse;
use Smile\ElasticsuiteCore\Search\Adapter\Elasticsuite\Response\QueryResponseFactory;

/**
 * Converts a NeuronaptiQ search result into the search response of Smile ElasticSuite
 *
 * The response is built from an array in the format of an Elasticsearch response, the facets of the search API
 * become its aggregations (layered navigation, SimpKo/project#324).
 *
 * @phpstan-import-type ProductHit from \NeuronaptiQ\Search\Model\SearchService
 * @phpstan-import-type Facet from \NeuronaptiQ\Search\Api\FacetedSearchApiClientInterface
 */
class QueryResponseBuilder
{
    /**
     * @var QueryResponseFactory
     */
    private QueryResponseFactory $queryResponseFactory;

    /**
     * @var AggregationsBuilder
     */
    private AggregationsBuilder $aggregationsBuilder;

    /**
     * @param QueryResponseFactory $queryResponseFactory
     * @param AggregationsBuilder $aggregationsBuilder
     */
    public function __construct(QueryResponseFactory $queryResponseFactory, AggregationsBuilder $aggregationsBuilder)
    {
        $this->queryResponseFactory = $queryResponseFactory;
        $this->aggregationsBuilder = $aggregationsBuilder;
    }

    /**
     * Builds the search response with the given products (in display order), total and facets
     *
     * @param array $hits
     * @param int $total
     * @param array $facets
     * @param list<string>|null $bucketNames Aggregations of the request, null: all
     * @return QueryResponse
     * @phpstan-param list<ProductHit> $hits
     * @phpstan-param list<Facet> $facets
     */
    public function build(array $hits, int $total, array $facets = [], ?array $bucketNames = null): QueryResponse
    {
        $aggregations = $this->aggregationsBuilder->build($facets, $total);
        if ($bucketNames !== null) {
            // Like Elasticsearch only the requested aggregations, e.g. ElasticSuite does not request the facets of
            // attributes below their minimum coverage
            $aggregations = array_intersect_key($aggregations, array_flip($bucketNames));
        }

        $documents = [];
        foreach ($hits as $hit) {
            $documents[] = [
                '_id' => (string)$hit['id'],
                '_score' => $hit['score'],
                // With a swatch filter (e.g. color) ElasticSuite shows the image of the child product among the
                // children_ids that matches the selection. The hit children of the API (variant mode "best": the
                // best matching variant) instead of all children of the index; without them its fallback fails
                // for a multiselect selection
                '_source' => ['children_ids' => $hit['childrenIds']],
            ];
        }

        return $this->queryResponseFactory->create([
            'searchResponse' => [
                'hits' => [
                    'hits' => $documents,
                    'total' => ['value' => $total, 'relation' => 'eq'],
                ],
                'aggregations' => $aggregations,
            ],
        ]);
    }
}
