<?php

declare(strict_types=1);

namespace Modules\Pricing\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Catalog\Models\Product;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;

class ProductPricingManager extends Component
{
    public ?int $selectedProductId = null;

    public ?int $selectedPriceBookId = null;

    public string $amount = '';

    public string $compareAt = '';

    public string $cost = '';

    public ?int $editingPriceId = null;

    public ?int $confirmToggleId = null;

    public function savePrice(): void
    {
        if (! auth()->user()?->can('pricing.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $this->validate([
            'selectedProductId' => ['required', 'integer'],
            'selectedPriceBookId' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);
        $priceBook = PriceBook::findOrFail($this->selectedPriceBookId);

        $minorAmount = (int) round(((float) $this->amount) * 100);
        $minorCompare = $this->compareAt !== '' ? (int) round(((float) $this->compareAt) * 100) : null;
        $minorCost = $this->cost !== '' ? (int) round(((float) $this->cost) * 100) : null;

        Price::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'price_book_id' => $this->selectedPriceBookId,
                'product_id' => $this->selectedProductId,
                'product_variant_id' => null,
            ],
            [
                'amount_minor' => $minorAmount,
                'compare_at_minor' => $minorCompare,
                'cost_minor' => $minorCost,
                'currency' => $priceBook->currency,
                'status' => 'active',
            ]
        );

        $this->reset(['amount', 'compareAt', 'cost', 'selectedProductId', 'selectedPriceBookId', 'editingPriceId']);
        session()->flash('success', 'Product price updated.');
    }

    public function editPrice(int $id): void
    {
        if (! auth()->user()?->can('pricing.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        $price = Price::where('tenant_id', $tenantId)->findOrFail($id);

        $this->editingPriceId = $price->id;
        $this->selectedProductId = $price->product_id;
        $this->selectedPriceBookId = $price->price_book_id;
        $this->amount = number_format($price->amount_minor / 100, 2, '.', '');
        $this->compareAt = $price->compare_at_minor !== null ? number_format($price->compare_at_minor / 100, 2, '.', '') : '';
        $this->cost = $price->cost_minor !== null ? number_format($price->cost_minor / 100, 2, '.', '') : '';
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingPriceId', 'selectedProductId', 'selectedPriceBookId', 'amount', 'compareAt', 'cost']);
    }

    public function openToggleConfirm(int $id): void
    {
        $this->confirmToggleId = $id;
    }

    public function cancelToggleConfirm(): void
    {
        $this->confirmToggleId = null;
    }

    public function togglePriceStatus(): void
    {
        if (! auth()->user()?->can('pricing.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->confirmToggleId === null) {
            return;
        }

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        $price = Price::where('tenant_id', $tenantId)->findOrFail($this->confirmToggleId);
        $price->update(['status' => $price->status === 'active' ? 'inactive' : 'active']);

        $this->confirmToggleId = null;
        session()->flash('success', $price->status === 'active' ? 'Price reactivated.' : 'Price deactivated.');
    }

    public function render(): View|Factory
    {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return view('pricing::livewire.product-pricing-manager', [
            'products' => Product::where('tenant_id', $tenantId)->get(),
            'priceBooks' => PriceBook::where('tenant_id', $tenantId)->get(),
            'prices' => Price::where('tenant_id', $tenantId)->with(['product', 'priceBook'])->get(),
        ])->layout('layouts.control-center', ['title' => 'Product Pricing']);
    }
}
