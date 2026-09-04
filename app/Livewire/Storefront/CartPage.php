<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\Models\Cart;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartQuantity;

class CartPage extends Component
{
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

    public function render(): View
    {
        $cartService = app(CartServiceInterface::class);
        $cart = $this->currentCart($cartService);
        $cart?->load('lines.product.translations', 'lines.variant');

        return view('theme::pages.cart', [
            'cart' => $cart,
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
