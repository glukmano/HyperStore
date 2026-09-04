<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Contracts\ProductTypeRegistryInterface;
use Modules\Catalog\Models\Product;
use Modules\Pricing\Contracts\PriceResolverInterface;
use Modules\Pricing\DTOs\PricingContext;
use Modules\Pricing\DTOs\PricingItem;

class ProductPage extends Component
{
    public string $sku;

    public ?int $variantId = null;

    public int $quantity = 1;

    public function mount(string $sku): void
    {
        $this->sku = $sku;
    }

    public function addToCart(CartServiceInterface $cartService): void
    {
        $context = app(ContextManager::class);
        $tenantId = $context->getTenant()->getId();
        $storeId = $context->getStore()->getId();

        $product = $this->findProduct((int) $tenantId);
        if ($product === null || $tenantId === null || $storeId === null) {
            session()->flash('error', 'Unable to add this item to your cart right now.');

            return;
        }

        $cart = $cartService->getOrCreateActiveCart(new CartContext(
            tenantId: (int) $tenantId,
            storeId: (int) $storeId,
            marketId: (int) ($context->getMarket()->getId() ?? 0),
            channelId: (int) ($context->getChannel()->getId() ?? 0),
            currency: $context->getCurrency()->getCode() ?? 'USD',
            locale: app()->getLocale(),
            userId: is_int(auth()->id()) ? auth()->id() : null,
            guestToken: session()->getId(),
        ));

        $cartService->addLine($cart, new CartLineItemData(
            productId: $product->id,
            variantId: $this->variantId,
            quantity: CartQuantity::fromInt(max(1, $this->quantity)),
        ));

        session()->flash('success', 'Added to cart.');
        $this->redirect(route('storefront.cart'), navigate: true);
    }

    public function render(): View
    {
        $context = app(ContextManager::class);
        $tenantId = $context->getTenant()->getId();

        $product = $this->findProduct((int) $tenantId);

        $priceResult = null;
        if ($product !== null && $tenantId !== null) {
            $priceResult = app(PriceResolverInterface::class)->resolve(
                new PricingItem(productId: $product->id, variantId: $this->variantId),
                new PricingContext(
                    tenantId: (int) $tenantId,
                    currency: $context->getCurrency()->getCode() ?? 'USD',
                    storeId: $context->getStore()->getId() !== null ? (int) $context->getStore()->getId() : null,
                    marketId: $context->getMarket()->getId() !== null ? (int) $context->getMarket()->getId() : null,
                    channelId: $context->getChannel()->getId() !== null ? (int) $context->getChannel()->getId() : null,
                )
            );
        }

        $template = 'default';
        if ($product !== null) {
            $registry = app(ProductTypeRegistryInterface::class);
            if ($registry->has($product->product_type)) {
                $template = $registry->get($product->product_type)->getStorefrontTemplate();
            }
        }

        $sectionView = "theme::sections.product-types.{$template}";
        if (! view()->exists($sectionView)) {
            $sectionView = 'theme::sections.product-types.default';
        }

        $title = $product !== null ? $product->name : 'Product';

        return view('theme::pages.product', [
            'product' => $product,
            'price' => $priceResult,
            'sectionView' => $sectionView,
        ])->layout('theme::layouts.app', ['title' => $title]);
    }

    private function findProduct(?int $tenantId): ?Product
    {
        if ($tenantId === null) {
            return null;
        }

        return Product::query()
            ->where('tenant_id', $tenantId)
            ->where('sku', $this->sku)
            ->where('status', 'active')
            ->with(['translations', 'variants'])
            ->first();
    }
}
