<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Services\ProductRecommendationService;

/**
 * Phase-19 Final Completion Delta §6: "Frequently Bought Together" and
 * "Related Products" on the Product page — the one existing
 * ProductRecommendationService, no separate recommendation engine.
 */
class ProductRecommendationsSection extends Component
{
    public int $productId;

    public function mount(int $productId): void
    {
        $this->productId = $productId;
    }

    public function render(ProductRecommendationService $recommendations): View
    {
        $context = app(ContextManager::class);
        $tenantId = $context->getTenant()->getId();
        $storeId = $context->getStore()->getId();
        $marketId = $context->getMarket()->getId();

        if ($tenantId === null || $storeId === null) {
            return view('theme::components.product-recommendations', [
                'frequentlyBoughtWith' => collect(),
                'relatedProducts' => collect(),
            ]);
        }

        $product = Product::find($this->productId);

        return view('theme::components.product-recommendations', [
            'frequentlyBoughtWith' => $recommendations->frequentlyBoughtWith((int) $tenantId, (int) $storeId, $this->productId, 6, $marketId !== null ? (int) $marketId : null),
            'relatedProducts' => $product !== null
                ? $recommendations->relatedByCategory((int) $tenantId, (int) $storeId, $product, 6, $marketId !== null ? (int) $marketId : null)
                : collect(),
        ]);
    }
}
