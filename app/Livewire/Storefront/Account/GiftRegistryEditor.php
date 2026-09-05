<?php

declare(strict_types=1);

namespace App\Livewire\Storefront\Account;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Catalog\Models\Product;
use Modules\Customers\Models\GiftRegistry;
use Modules\Customers\Services\GiftRegistryService;

class GiftRegistryEditor extends Component
{
    public GiftRegistry $registry;

    public string $sku = '';

    public int $quantityRequested = 1;

    public function mount(GiftRegistry $registry): void
    {
        abort_if($registry->user_id !== auth()->id(), 403);

        $this->registry = $registry;
    }

    public function addItem(GiftRegistryService $service): void
    {
        $this->validate([
            'sku' => 'required|string',
            'quantityRequested' => 'required|integer|min:1',
        ]);

        $tenantId = (int) app(ContextManager::class)->getTenant()->getId();
        $product = Product::query()->where('tenant_id', $tenantId)->where('sku', $this->sku)->first();

        if ($product === null) {
            session()->flash('error', __('Product not found for that SKU.'));

            return;
        }

        $service->addItem($this->registry, $product->id, null, $this->quantityRequested);
        $this->reset('sku', 'quantityRequested');
        $this->quantityRequested = 1;
    }

    public function makePublic(): void
    {
        $this->registry->visibility = 'public';
        $this->registry->save();
    }

    public function render(): View
    {
        $this->registry->load('items.product.translations');

        return view('theme::pages.account.gift-registry-editor', ['registry' => $this->registry])
            ->layout('theme::layouts.app', ['title' => $this->registry->title]);
    }
}
