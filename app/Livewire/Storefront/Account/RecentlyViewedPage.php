<?php

declare(strict_types=1);

namespace App\Livewire\Storefront\Account;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Customers\Models\RecentlyViewedItem;

class RecentlyViewedPage extends Component
{
    public function render(): View
    {
        $tenantId = (int) app(ContextManager::class)->getTenant()->getId();

        $query = RecentlyViewedItem::query()->where('tenant_id', $tenantId);
        $query = auth()->check()
            ? $query->where('user_id', auth()->id())
            : $query->where('session_id', session()->getId());

        $items = $query->with('product.translations')->orderByDesc('viewed_at')->limit(50)->get();

        return view('theme::pages.account.recently-viewed', ['items' => $items])
            ->layout('theme::layouts.app', ['title' => __('Recently Viewed')]);
    }
}
