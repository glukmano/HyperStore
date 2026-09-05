<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use App\Core\Localization\Contracts\LocaleManagerInterface;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Search\Contracts\SearchServiceInterface;
use Modules\Search\DTOs\SearchQuery;
use Modules\Search\DTOs\SearchResultSet;

class SearchResultsPage extends Component
{
    #[Url]
    public string $q = '';

    public ?int $lastSearchQueryId = null;

    public function recordClick(int $productId, int $position, SearchServiceInterface $searchService): void
    {
        if ($this->lastSearchQueryId === null) {
            return;
        }

        $tenantId = (int) app(ContextManager::class)->getTenant()->getId();
        $searchService->recordClick($this->lastSearchQueryId, $tenantId, $productId, $position);
    }

    public function render(SearchServiceInterface $searchService, LocaleManagerInterface $localeManager): View
    {
        $contextManager = app(ContextManager::class);

        $results = ($this->q !== '' && $contextManager->hasTenant() && $contextManager->hasStore())
            ? $searchService->search(new SearchQuery(
                tenantId: (int) $contextManager->getTenant()->getId(),
                storeId: (int) $contextManager->getStore()->getId(),
                channelId: $contextManager->hasChannel() ? (int) $contextManager->getChannel()->getId() : null,
                term: $this->q,
                locale: $localeManager->getLocale(),
            ))
            : new SearchResultSet(hits: [], total: 0, page: 1, perPage: 24);

        $this->lastSearchQueryId = $results->searchQueryId;

        return view('theme::pages.search-results', ['results' => $results, 'query' => $this->q])
            ->layout('theme::layouts.app', ['title' => 'Search']);
    }
}
