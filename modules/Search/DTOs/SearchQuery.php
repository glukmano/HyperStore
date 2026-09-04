<?php

declare(strict_types=1);

namespace Modules\Search\DTOs;

/**
 * Every storefront-facing SearchQuery MUST carry tenant/store/channel
 * context — there is no nullable escape hatch, so application code cannot
 * construct an unscoped query (Master §26: search index is not source of
 * truth; a storefront caller can never bypass its own tenant/store scope).
 */
final readonly class SearchQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public int $tenantId,
        public int $storeId,
        public ?int $channelId,
        public string $term,
        public string $locale,
        public array $filters = [],
        public int $page = 1,
        public int $perPage = 24,
        public ?string $sort = null,
    ) {}
}
