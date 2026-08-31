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

    public function savePrice(): void
    {
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

        $this->reset(['amount', 'compareAt', 'cost']);
        session()->flash('success', 'Product price updated.');
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
