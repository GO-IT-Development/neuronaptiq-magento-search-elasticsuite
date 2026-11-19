<?php
declare(strict_types=1);

namespace NeuronaptiQ\SearchElasticsuite\Model;

/**
 * Remembers whether the quick search of the current request was answered by NeuronaptiQ
 *
 * Set by the SearchEnginePlugin, read by plugins that adapt the ElasticSuite behavior to NeuronaptiQ results
 * (e.g. the spellcheck note of the search result page).
 */
class SearchState
{
    /**
     * @var bool
     */
    private bool $answeredByNeuronaptiq = false;

    /**
     * Sets whether the quick search was answered by NeuronaptiQ (false after a fallback to ElasticSuite)
     *
     * @param bool $answered
     * @return void
     */
    public function setAnsweredByNeuronaptiq(bool $answered): void
    {
        $this->answeredByNeuronaptiq = $answered;
    }

    /**
     * Checks whether the quick search was answered by NeuronaptiQ
     *
     * @return bool
     */
    public function isAnsweredByNeuronaptiq(): bool
    {
        return $this->answeredByNeuronaptiq;
    }
}
