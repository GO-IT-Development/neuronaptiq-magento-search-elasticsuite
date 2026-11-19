<?php
declare(strict_types=1);

namespace NeuronaptiQ\SearchElasticsuite\Exception;

/**
 * The ElasticSuite request contains a filter the search API cannot apply, ElasticSuite answers the request
 * (SimpKo/project#324)
 */
class UnsupportedFilterException extends \RuntimeException
{
}
