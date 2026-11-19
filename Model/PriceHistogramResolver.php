<?php
declare(strict_types=1);

namespace NeuronaptiQ\SearchElasticsuite\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use NeuronaptiQ\Search\Model\Search\PriceRangesResolver;

/**
 * Price ranges of the search API for the price slider of ElasticSuite (SimpKo/project#324)
 *
 * ElasticSuite builds the slider from a price histogram: interval price_range_step with the calculation "Manual",
 * otherwise 1. Only filled intervals are returned, there is no maximum number of intervals. The search API is
 * asked for ranges with the same step.
 *
 * @phpstan-import-type PriceRangesParam from \NeuronaptiQ\Search\Model\Search\SearchFilters
 */
class PriceHistogramResolver
{
    private const DEFAULT_INTERVAL = 1.0;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Returns the priceRanges parameter of the search request
     *
     * @return array
     * @phpstan-return PriceRangesParam
     */
    public function resolve(): array
    {
        $calculation = (string)$this->scopeConfig->getValue(
            PriceRangesResolver::XML_PATH_RANGE_CALCULATION,
            ScopeInterface::SCOPE_STORE
        );
        // Like ElasticSuite: only whole steps (cast to int)
        $step = (int)$this->scopeConfig->getValue(
            PriceRangesResolver::XML_PATH_RANGE_STEP,
            ScopeInterface::SCOPE_STORE
        );
        if ($calculation === PriceRangesResolver::CALCULATION_MANUAL && $step > 0) {
            return ['step' => (float)$step];
        }

        return ['step' => self::DEFAULT_INTERVAL];
    }
}
