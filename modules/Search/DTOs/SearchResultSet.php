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
    ) {}
}
