<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Catalog\Models\Product;

class ComparePage extends Component
{
    public function removeProduct(int $productId): void
    {
        $ids = session()->get('compare_product_ids', []);
        session()->put('compare_product_ids', array_values(array_diff($ids, [$productId])));
    }

    public function clear(): void
    {
        session()->forget('compare_product_ids');
    }

    public function render(): View
    {
        $tenantId = (int) app(ContextManager::class)->getTenant()->getId();
        $ids = session()->get('compare_product_ids', []);

        $products = $ids === []
            ? collect()
            : Product::query()->where('tenant_id', $tenantId)->whereIn('id', $ids)->with('translations')->get();

        return view('theme::pages.compare', ['products' => $products])
            ->layout('theme::layouts.app', ['title' => __('Compare Products')]);
    }
}
