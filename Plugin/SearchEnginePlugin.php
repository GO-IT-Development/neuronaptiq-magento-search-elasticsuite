<?php
declare(strict_types=1);

namespace NeuronaptiQ\SearchElasticsuite\Plugin;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Search\RequestInterface;
use Magento\Framework\Search\ResponseInterface;
use Magento\Search\Model\SearchEngine;
use NeuronaptiQ\Search\Exception\EmptySearchResultException;
use NeuronaptiQ\Search\Model\Config;
use NeuronaptiQ\Search\Model\PageCacheGuard;
use NeuronaptiQ\Search\Model\SearchService;
use NeuronaptiQ\SearchElasticsuite\Exception\UnsupportedFilterException;
use NeuronaptiQ\SearchElasticsuite\Model\PageSizeResolver;
use NeuronaptiQ\SearchElasticsuite\Model\PriceHistogramResolver;
use NeuronaptiQ\SearchElasticsuite\Model\QueryResponseBuilder;
use NeuronaptiQ\SearchElasticsuite\Model\RequestFilterReader;
use NeuronaptiQ\SearchElasticsuite\Model\SearchState;
use Psr\Log\LoggerInterface;
use Smile\ElasticsuiteCore\Search\RequestInterface as ElasticsuiteRequestInterface;

/**
 * Answers the quick search requests of Smile ElasticSuite with the NeuronaptiQ search result
 *
 * The ElasticSuite product collection of the search result page sends two requests: the page (from/size) and a
 * count request without page size for the total. Both are answered from the same API result.
 *
 * The selection of the layered navigation is read from the request (RequestFilterReader) and sent to the API, its
 * facets become the aggregations of the response (SimpKo/project#324). A filter the API cannot apply leaves the
 * request to ElasticSuite.
 *
 * Only requests of the search result page (action catalogsearch_result_index) are handled. Other requests (category
 * pages, product lists, autocomplete, also when they use the request name quick_search_container) and requests
 * without query are passed on. On API errors and an empty first page ElasticSuite searches as usual and the page is
 * kept out of the full page cache.
 * The outcome is remembered in SearchState (see SpellcheckPlugin).
 */
class SearchEnginePlugin
{
    private const REQUEST_NAME_QUICK_SEARCH = 'quick_search_container';
    private const ACTION_SEARCH_RESULT = 'catalogsearch_result_index';

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var SearchService
     */
    private SearchService $searchService;

    /**
     * @var QueryResponseBuilder
     */
    private QueryResponseBuilder $queryResponseBuilder;

    /**
     * @var PageSizeResolver
     */
    private PageSizeResolver $pageSizeResolver;

    /**
     * @var PageCacheGuard
     */
    private PageCacheGuard $pageCacheGuard;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var SearchState
     */
    private SearchState $searchState;

    /**
     * @var HttpRequest
     */
    private HttpRequest $httpRequest;

    /**
     * @var RequestFilterReader
     */
    private RequestFilterReader $requestFilterReader;

    /**
     * @var PriceHistogramResolver
     */
    private PriceHistogramResolver $priceHistogramResolver;

    /**
     * @param Config $config
     * @param SearchService $searchService
     * @param QueryResponseBuilder $queryResponseBuilder
     * @param PageSizeResolver $pageSizeResolver
     * @param PageCacheGuard $pageCacheGuard
     * @param LoggerInterface $logger
     * @param SearchState $searchState
     * @param HttpRequest $httpRequest
     * @param RequestFilterReader $requestFilterReader
     * @param PriceHistogramResolver $priceHistogramResolver
     */
    public function __construct(
        Config $config,
        SearchService $searchService,
        QueryResponseBuilder $queryResponseBuilder,
        PageSizeResolver $pageSizeResolver,
        PageCacheGuard $pageCacheGuard,
        LoggerInterface $logger,
        SearchState $searchState,
        HttpRequest $httpRequest,
        RequestFilterReader $requestFilterReader,
        PriceHistogramResolver $priceHistogramResolver
    ) {
        $this->config = $config;
        $this->searchService = $searchService;
        $this->queryResponseBuilder = $queryResponseBuilder;
        $this->pageSizeResolver = $pageSizeResolver;
        $this->pageCacheGuard = $pageCacheGuard;
        $this->logger = $logger;
        $this->searchState = $searchState;
        $this->httpRequest = $httpRequest;
        $this->requestFilterReader = $requestFilterReader;
        $this->priceHistogramResolver = $priceHistogramResolver;
    }

    /**
     * Around plugin on SearchEngine::search to answer ElasticSuite quick search requests via NeuronaptiQ
     *
     * @param SearchEngine $subject
     * @param callable $proceed
     * @param RequestInterface $request
     * @return ResponseInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundSearch(SearchEngine $subject, callable $proceed, RequestInterface $request)
    {
        // Only the search result page: the autocomplete of some setups uses quick_search_container as well, its
        // product suggestions come from NeuronaptiQ_Search (suggest API), ElasticSuite's are replaced anyway
        if (!$request instanceof ElasticsuiteRequestInterface
            || $request->getName() !== self::REQUEST_NAME_QUICK_SEARCH
            || $this->httpRequest->getFullActionName() !== self::ACTION_SEARCH_RESULT
            || !$this->config->isSearchEnabled()
        ) {
            return $proceed($request);
        }

        $queryText = $this->searchService->getQueryText();
        if ($queryText === '') {
            return $proceed($request);
        }

        try {
            $filters = $this->requestFilterReader->read($request)
                ->withFacets($this->priceHistogramResolver->resolve());
        } catch (UnsupportedFilterException $e) {
            // A filter the API cannot apply: ElasticSuite answers with its own result and facets (cacheable)
            $this->logger->debug(
                '[NeuronaptiQ_SearchElasticsuite] ' . $e->getMessage() . ', ElasticSuite answers the request'
            );
            $this->searchState->setAnsweredByNeuronaptiq(false);
            return $proceed($request);
        }

        $size = (int)$request->getSize();
        $isCountRequest = $size <= 0;
        // Above the API maximum it is no page of the product list: faceted data requested before the toolbar sets
        // the page size, the collection then requests all hits (SimpKo/project#221). Answered like the count request
        // with the hits of the current page, so its facets match the selection; the product list follows.
        $isPageRequest = !$isCountRequest && $size <= SearchService::MAX_LIMIT;
        if ($isPageRequest) {
            $page = intdiv(max(0, (int)$request->getFrom()), $size) + 1;
        } else {
            // Same parameters as the page request, so both use the same API result
            $page = $this->searchService->getPage();
            $size = $this->pageSizeResolver->getPageSize();
        }

        try {
            $sortOrder = $this->searchService->getSortOrder();
            $result = $this->searchService->search($queryText, $page, $size, $sortOrder, $filters);
            if (!$isPageRequest && $page > 1 && empty($result['hits']) && $result['total'] === 0) {
                // Page beyond the API's result pool (total 0): with the total of the first page, ElasticSuite limits
                // the page to the last one instead of reporting no results. Counted only, the search service does
                // not store it as search of the session (SimpKo/project#363)
                $count = $this->searchService->search($queryText, 1, $size, $sortOrder, $filters->withCountOnly());
                $result = ['hits' => [], 'total' => $count['total'], 'facets' => $count['facets']];
            }
        } catch (EmptySearchResultException $e) {
            // Empty results (e.g. for requests classified as bots) are not shown and must not be cached
            $this->logger->debug(
                '[NeuronaptiQ_SearchElasticsuite] ' . $e->getMessage() . ', falling back to the ElasticSuite search'
            );
            $this->pageCacheGuard->disableCaching();
            $this->searchState->setAnsweredByNeuronaptiq(false);
            return $proceed($request);
        } catch (\Throwable $e) {
            $this->logger->error(
                '[NeuronaptiQ_SearchElasticsuite] SearchEnginePlugin error: ' . $e->getMessage(),
                ['exception' => $e]
            );
            $this->pageCacheGuard->disableCaching();
            $this->searchState->setAnsweredByNeuronaptiq(false);
            return $proceed($request);
        }

        $this->searchState->setAnsweredByNeuronaptiq(true);
        $bucketNames = [];
        foreach ($request->getAggregation() as $bucket) {
            $bucketNames[] = (string)$bucket->getName();
        }

        return $this->queryResponseBuilder->build(
            $isCountRequest ? [] : $result['hits'],
            $result['total'],
            $result['facets'],
            $bucketNames
        );
    }
}
