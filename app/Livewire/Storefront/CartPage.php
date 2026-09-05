<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use App\Livewire\Storefront\Concerns\BuildsCartContext;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\Models\Cart;
use Modules\Cart\Models\CartLine;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Customers\Contracts\SaveForLaterServiceInterface;
use Modules\Customers\Models\SavedForLaterItem;
use Modules\Pricing\Contracts\PriceResolverInterface;
use Modules\Pricing\DTOs\PricingContext;
use Modules\Pricing\DTOs\PricingItem;

class CartPage extends Component
{
    use BuildsCartContext;

    public string $couponCode = '';

    public function updateQuantity(int $lineId, int $quantity, CartServiceInterface $cartService): void
    {
        $cart = $this->currentCart($cartService);
        if ($cart === null || $quantity < 1) {
            return;
        }

        try {
            $cartService->updateQuantity($cart, $lineId, CartQuantity::fromInt($quantity));
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function removeLine(int $lineId, CartServiceInterface $cartService): void
    {
        $cart = $this->currentCart($cartService);
        if ($cart === null) {
            return;
        }

        $cartService->removeLine($cart, $lineId);
    }

    public function applyCoupon(CartServiceInterface $cartService): void
    {
        $cart = $this->currentCart($cartService);
        if ($cart === null || trim($this->couponCode) === '') {
            return;
        }

        try {
            $cartService->applyCoupon($cart, trim($this->couponCode));
            session()->flash('success', 'Coupon applied.');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function proceedToCheckout(): void
    {
        $this->redirect(route('storefront.checkout'), navigate: true);
    }

    public function saveForLater(int $lineId, CartServiceInterface $cartService, PriceResolverInterface $priceResolver): void
    {
        if (! auth()->check()) {
            session()->flash('error', __('Please sign in to save items for later.'));
            $this->redirect(route('login'), navigate: true);

            return;
        }

        $cart = $this->currentCart($cartService);
        if ($cart === null) {
            return;
        }

        /** @var CartLine|null $line */
        $line = CartLine::query()->where('id', $lineId)->where('cart_id', $cart->id)->first();
        if ($line === null) {
            return;
        }

        $context = app(ContextManager::class);
        $priceResult = $priceResolver->resolve(
            new PricingItem(productId: $line->product_id, variantId: $line->variant_id),
            new PricingContext(
                tenantId: $cart->tenant_id,
                currency: $context->getCurrency()->getCode() ?? 'USD',
                storeId: $cart->store_id,
                marketId: $cart->market_id,
                channelId: $cart->channel_id,
            ),
        );

        $unitPriceMinor = $priceResult?->unitPrice->getMinorAmount() ?? ($line->display_unit_price_minor ?? 0);
        $currency = $priceResult?->unitPrice->getCurrencyCode() ?? ($line->display_currency ?? 'USD');

        /** @var User $user */
        $user = auth()->user();

        app(SaveForLaterServiceInterface::class)->saveForLater(
            $user,
            $line->product_id,
            $line->variant_id,
            $line->getQuantityVO()->toInt(),
            $unitPriceMinor,
            $currency,
        );

        $cartService->removeLine($cart, $lineId);
        session()->flash('success', __('Saved for later.'));
    }

    public function moveToCart(int $savedItemId, SaveForLaterServiceInterface $saveForLaterService): void
    {
        $item = SavedForLaterItem::query()->where('id', $savedItemId)->where('user_id', auth()->id())->first();
        if ($item === null) {
            return;
        }

        $cartContext = $this->buildCartContext();
        if ($cartContext === null) {
            return;
        }

        $saveForLaterService->moveToCart($item, $cartContext);
        session()->flash('success', __('Moved to cart.'));
    }

    public function removeSavedItem(int $savedItemId, SaveForLaterServiceInterface $saveForLaterService): void
    {
        $item = SavedForLaterItem::query()->where('id', $savedItemId)->where('user_id', auth()->id())->first();
        if ($item !== null) {
            $saveForLaterService->removeSavedItem($item);
        }
    }

    public function render(): View
    {
        $cartService = app(CartServiceInterface::class);
        $cart = $this->currentCart($cartService);
        $cart?->load('lines.product.translations', 'lines.variant');

        $savedItems = auth()->check()
            ? SavedForLaterItem::query()->where('user_id', auth()->id())->with('product.translations', 'variant')->latest('added_at')->get()
            : collect();

        return view('theme::pages.cart', [
            'cart' => $cart,
            'savedItems' => $savedItems,
        ])->layout('theme::layouts.app', ['title' => 'Cart', 'cartItemCount' => $cart?->lines?->count() ?? 0]);
    }

    private function currentCart(CartServiceInterface $cartService): ?Cart
    {
        $context = app(ContextManager::class);
        $tenantId = $context->getTenant()->getId();
        $storeId = $context->getStore()->getId();

        if ($tenantId === null || $storeId === null) {
            return null;
        }

        return $cartService->getOrCreateActiveCart(new CartContext(
            tenantId: (int) $tenantId,
            storeId: (int) $storeId,
            marketId: (int) ($context->getMarket()->getId() ?? 0),
            channelId: (int) ($context->getChannel()->getId() ?? 0),
            currency: $context->getCurrency()->getCode() ?? 'USD',
            locale: app()->getLocale(),
            userId: is_int(auth()->id()) ? auth()->id() : null,
            guestToken: session()->getId(),
        ));
    }
}
