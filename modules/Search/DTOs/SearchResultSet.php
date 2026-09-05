<?php

declare(strict_types=1);

namespace Modules\Search\DTOs;

final readonly class SearchResultSet
{
    /**
     * @param  list<array<string, mixed>>  $hits
     * @param  array<string, mixed>  $facets
     */
    public function __construct(
        public array $hits,
        public int $total,
        public int $page,
        public int $perPage,
        public array $facets = [],
        /**
         * The search_queries row id this result was recorded under, or null
         * when analytics recording was skipped (e.g. an empty term). Passed
         * back to SearchServiceInterface::recordClick() so one query
         * execution can own multiple click records.
         */
        public ?int $searchQueryId = null,
    ) {}
}
