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
}
