<?php
declare(strict_types=1);

namespace NeuronaptiQ\SearchElasticsuite\Plugin;

use NeuronaptiQ\SearchElasticsuite\Model\SearchState;
use Smile\ElasticsuiteCatalog\Model\ResourceModel\Product\Fulltext\Collection;

/**
 * Suppresses the ElasticSuite spellcheck result when the quick search was answered by NeuronaptiQ
 *
 * ElasticSuite checks the spelling of the query against its own index before the search. Terms that are not in
 * the index (e.g. semantic queries) are classified as misspelled, and the search result page shows the note
 * "No exact results found … the displayed items are the closest matches" (and stores the query as
 * spellchecked). This does not apply to NeuronaptiQ results.
 */
class SpellcheckPlugin
{
    /**
     * @var SearchState
     */
    private SearchState $searchState;

    /**
     * @param SearchState $searchState
     */
    public function __construct(SearchState $searchState)
    {
        $this->searchState = $searchState;
    }

    /**
     * Reports the collection as not spellchecked when NeuronaptiQ answered the quick search
     *
     * @param Collection $subject
     * @param bool $result
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterIsSpellchecked(Collection $subject, $result): bool
    {
        return (bool)$result && !$this->searchState->isAnsweredByNeuronaptiq();
    }
}
