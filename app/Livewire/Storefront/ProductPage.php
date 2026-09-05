<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use App\Livewire\Storefront\Concerns\BuildsCartContext;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Contracts\ProductTypeRegistryInterface;
use Modules\Catalog\Models\Product;
use Modules\Customers\Services\AlertSubscriptionService;
use Modules\Customers\Services\FollowService;
use Modules\Customers\Services\RecentlyViewedService;
use Modules\Customers\Services\WishlistService;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Pricing\Contracts\PriceResolverInterface;
use Modules\Pricing\DTOs\PricingContext;
use Modules\Pricing\DTOs\PricingItem;

class ProductPage extends Component
{
    use BuildsCartContext;

    public string $sku;

    public ?int $variantId = null;

    public int $quantity = 1;

    public function mount(string $sku): void
    {
        $this->sku = $sku;
    }

    public function toggleWishlist(WishlistService $wishlistService): void
    {
        $context = app(ContextManager::class);
        $product = $this->findProduct((int) $context->getTenant()->getId());
        if ($product === null) {
            return;
        }

        /** @var User|null $user */
        $user = auth()->user();
        $wishlist = $wishlistService->defaultWishlistForIdentity($user, $user === null ? session()->getId() : null);

        $existing = $wishlist->items()->where('product_id', $product->id)->where('variant_id', $this->variantId)->first();
        if ($existing !== null) {
            $wishlistService->removeItem($wishlist, $product->id, $this->variantId);
            session()->flash('success', __('Removed from wishlist.'));
        } else {
            $wishlistService->addItem($wishlist, $product->id, $this->variantId);
            session()->flash('success', __('Added to wishlist.'));
        }
    }

    /**
     * Compare is session-only, never persisted (Phase-17 plan §9 scope
     * decision) — even for authenticated users. Capped at 4 products.
     */
    public function toggleCompare(): void
    {
        $context = app(ContextManager::class);
        $product = $this->findProduct((int) $context->getTenant()->getId());
        if ($product === null) {
            return;
        }

        $ids = session()->get('compare_product_ids', []);

        if (in_array($product->id, $ids, true)) {
            session()->put('compare_product_ids', array_values(array_diff($ids, [$product->id])));

            return;
        }

        if (count($ids) >= 4) {
            session()->flash('error', __('You can compare up to 4 products at a time.'));

            return;
        }

        $ids[] = $product->id;
        session()->put('compare_product_ids', $ids);
    }

    public function toggleFollow(FollowService $followService): void
    {
        if (! $this->requireAuth()) {
            return;
        }

        $context = app(ContextManager::class);
        $product = $this->findProduct((int) $context->getTenant()->getId());
        if ($product === null) {
            return;
        }

        /** @var User $user */
        $user = auth()->user();

        if ($followService->isFollowingProduct($user, $product->id)) {
            $followService->unfollowProduct($user, $product->id);
            session()->flash('success', __('Unfollowed this product.'));
        } else {
            $followService->followProduct($user, $product->id);
            session()->flash('success', __('Now following this product.'));
        }
    }

    public function subscribePriceDropAlert(AlertSubscriptionService $alerts, PriceResolverInterface $priceResolver): void
    {
        if (! $this->requireAuth()) {
            return;
        }

        $context = app(ContextManager::class);
        $tenantId = (int) $context->getTenant()->getId();
        $product = $this->findProduct($tenantId);
        if ($product === null) {
            return;
        }

        $pricingContext = new PricingContext(
            tenantId: $tenantId,
            currency: $context->getCurrency()->getCode() ?? 'USD',
            storeId: $context->getStore()->getId() !== null ? (int) $context->getStore()->getId() : null,
            marketId: $context->getMarket()->getId() !== null ? (int) $context->getMarket()->getId() : null,
            channelId: $context->getChannel()->getId() !== null ? (int) $context->getChannel()->getId() : null,
        );
        $priceResult = $priceResolver->resolve(new PricingItem(productId: $product->id, variantId: $this->variantId), $pricingContext);
        if ($priceResult === null) {
            return;
        }

        /** @var User $user */
        $user = auth()->user();

        $alerts->subscribeToPriceDrop(
            $user,
            $product->id,
            $this->variantId,
            $priceResult->unitPrice->getMinorAmount(),
            $priceResult->unitPrice->getCurrencyCode(),
            storeId: $pricingContext->storeId,
            channelId: $pricingContext->channelId,
            marketId: $pricingContext->marketId,
        );

        session()->flash('success', __('We will email you if the price drops.'));
    }

    public function subscribeBackInStockAlert(AlertSubscriptionService $alerts): void
    {
        if (! $this->requireAuth()) {
            return;
        }

        $context = app(ContextManager::class);
        $tenantId = (int) $context->getTenant()->getId();
        $storeId = $context->getStore()->getId();
        $product = $this->findProduct($tenantId);
        if ($product === null || $storeId === null) {
            return;
        }

        /** @var User $user */
        $user = auth()->user();

        $alerts->subscribeToBackInStock($user, $product->id, $this->variantId, (int) $storeId);

        session()->flash('success', __('We will email you when this item is back in stock.'));
    }

    private function requireAuth(): bool
    {
        if (auth()->check()) {
            return true;
        }

        session()->flash('error', __('Please sign in first.'));
        $this->redirect(route('login'), navigate: true);

        return false;
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

        $isInWishlist = false;
        $isFollowing = false;
        $availability = null;

        if ($product !== null && $tenantId !== null) {
            /** @var User|null $user */
            $user = auth()->user();

            $wishlistService = app(WishlistService::class);
            $wishlist = $wishlistService->defaultWishlistForIdentity($user, $user === null ? session()->getId() : null);
            $isInWishlist = $wishlist->items()->where('product_id', $product->id)->where('variant_id', $this->variantId)->exists();

            if ($user !== null) {
                $isFollowing = app(FollowService::class)->isFollowingProduct($user, $product->id);
            }

            if ($context->getStore()->getId() !== null) {
                $availability = app(InventoryAvailabilityService::class)->check(
                    $product->id,
                    $this->variantId,
                    new InventoryContext(tenantId: (int) $tenantId, storeId: (int) $context->getStore()->getId()),
                );
            }

            app(RecentlyViewedService::class)->recordView($product->id, $user, $user === null ? session()->getId() : null);
        }

        $isInCompare = $product !== null && in_array($product->id, session()->get('compare_product_ids', []), true);

        return view('theme::pages.product', [
            'product' => $product,
            'price' => $priceResult,
            'sectionView' => $sectionView,
            'isInWishlist' => $isInWishlist,
            'isFollowing' => $isFollowing,
            'availability' => $availability,
            'isInCompare' => $isInCompare,
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
