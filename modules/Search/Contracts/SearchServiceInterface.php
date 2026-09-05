<?php

declare(strict_types=1);

namespace Modules\Search\Contracts;

use Modules\Search\DTOs\SearchQuery;
use Modules\Search\DTOs\SearchResultSet;

/**
 * The ONE authoritative search contract — application code (storefront
 * search Livewire component, Control Center admin search, future CMS/blog
 * search) never touches Meilisearch's client or Scout's builder directly
 * outside modules/Search/.
 */
interface SearchServiceInterface
{
    public function search(SearchQuery $query): SearchResultSet;

    /**
     * Records a click against a prior search execution. One search_queries
     * row can own many search_clicks rows — a shopper opening several
     * results from the same query is not lost the way a single
     * clicked_product_id column would lose it.
     */
    public function recordClick(int $searchQueryId, int $tenantId, int $productId, ?int $resultPosition = null): void;
}
