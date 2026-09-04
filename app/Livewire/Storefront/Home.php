<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Catalog\Models\Category;
use Modules\Catalog\Models\Product;

class Home extends Component
{
    public function render(): View
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId();

        $categories = $tenantId !== null
            ? Category::query()->where('tenant_id', $tenantId)->where('status', 'active')->whereNull('parent_id')
                ->with('translations')->orderBy('sort_order')->limit(8)->get()
            : collect();

        $products = $tenantId !== null
            ? Product::query()->where('tenant_id', $tenantId)->where('status', 'active')
                ->with('translations')->latest('id')->limit(12)->get()
            : collect();

        return view('theme::pages.home', [
            'categories' => $categories,
            'products' => $products,
        ])->layout('theme::layouts.app', ['title' => 'Home']);
    }
}
