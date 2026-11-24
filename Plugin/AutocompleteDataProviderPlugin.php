<?php
declare(strict_types=1);

namespace NeuronaptiQ\SearchElasticsuite\Plugin;

use Magento\Search\Model\Autocomplete\DataProviderInterface;
use Magento\Search\Model\Autocomplete\ItemInterface;
use NeuronaptiQ\Search\Model\Autocomplete\SuggestState;

/**
 * Skips the ElasticSuite autocomplete suggestions (products, categories) that NeuronaptiQ replaces
 *
 * The autocomplete plugin of NeuronaptiQ_Search drops the suggestions of the types it delivers itself. Without this
 * plugin ElasticSuite would still load and render them (products with price and image, an own category search).
 * The suggestions of the other types (terms, attributes) and all suggestions on the Magento fallback stay unchanged.
 */
class AutocompleteDataProviderPlugin
{
    /**
     * @var SuggestState
     */
    private SuggestState $suggestState;

    /**
     * @param SuggestState $suggestState
     */
    public function __construct(SuggestState $suggestState)
    {
        $this->suggestState = $suggestState;
    }

    /**
     * No suggestions if NeuronaptiQ delivers the type of the data provider
     *
     * @param DataProviderInterface $subject
     * @param callable $proceed
     * @return ItemInterface[]
     */
    public function aroundGetItems(DataProviderInterface $subject, callable $proceed)
    {
        if (method_exists($subject, 'getType') && $this->suggestState->isReplaced((string)$subject->getType())) {
            return [];
        }

        return $proceed();
    }
}
