<?php

declare(strict_types=1);

namespace Modules\Search\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class SearchAnalyticsDashboard extends Component
{
    public function render(): View|Factory
    {
        $this->authorizeManage();

        $tenantId = $this->tenantId();

        $topQueries = DB::table('search_queries')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('normalized_query, count(*) as query_count, sum(result_count) as total_results')
            ->groupBy('normalized_query')
            ->orderByDesc('query_count')
            ->limit(20)
            ->get();

        $noResultQueries = DB::table('search_queries')
            ->where('tenant_id', $tenantId)
            ->where('result_count', 0)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('normalized_query, count(*) as query_count')
            ->groupBy('normalized_query')
            ->orderByDesc('query_count')
            ->limit(20)
            ->get();

        $totalSearches = DB::table('search_queries')->where('tenant_id', $tenantId)->where('created_at', '>=', now()->subDays(30))->count();
        $totalClicks = DB::table('search_clicks')->where('tenant_id', $tenantId)->where('created_at', '>=', now()->subDays(30))->count();

        return view('livewire.control-center.search.search-analytics-dashboard', [
            'topQueries' => $topQueries,
            'noResultQueries' => $noResultQueries,
            'totalSearches' => $totalSearches,
            'totalClicks' => $totalClicks,
        ])->layout('layouts.control-center', ['title' => 'Search Analytics']);
    }

    private function tenantId(): int
    {
        return (int) app(ContextManager::class)->getTenant()->getId();
    }

    private function authorizeManage(): void
    {
        if (! auth()->user()?->can('search.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }
}
