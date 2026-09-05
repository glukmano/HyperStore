<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use App\Livewire\Storefront\Concerns\BuildsCartContext;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Services\ProductRecommendationService;

/**
 * Phase-19 Final Completion Delta §6: cross-sell/upsell suggestions on the
 * Cart page — the one existing ProductRecommendationService, keyed off the
 * products already in the cart, excluding anything already in the cart.
 */
class CartRecommendationsSection extends Component
{
    use BuildsCartContext;

    public function render(CartServiceInterface $cartService, ProductRecommendationService $recommendations): View
    {
        $context = app(ContextManager::class);
        $tenantId = $context->getTenant()->getId();
        $storeId = $context->getStore()->getId();
        $marketId = $context->getMarket()->getId();

        $cartContext = $this->buildCartContext();
        if ($tenantId === null || $storeId === null || $cartContext === null) {
            return view('theme::components.cart-recommendations', ['suggestions' => collect()]);
        }

        $cart = $cartService->getOrCreateActiveCart($cartContext);
        $cart->loadMissing('lines');
        $cartProductIds = $cart->lines->pluck('product_id')->unique();

        $suggestions = collect();
        foreach ($cartProductIds as $productId) {
            $product = Product::find((int) $productId);
            if ($product === null) {
                continue;
            }

            foreach ($recommendations->crossSellUpsell((int) $tenantId, (int) $storeId, $product, 4, $marketId !== null ? (int) $marketId : null) as $suggestion) {
                if (! $cartProductIds->contains($suggestion->id) && ! $suggestions->contains('id', $suggestion->id)) {
                    $suggestions->push($suggestion);
                }
            }
        }

        return view('theme::components.cart-recommendations', [
            'suggestions' => $suggestions->take(6),
        ]);
    }
}
