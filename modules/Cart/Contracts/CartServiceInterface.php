<?php

declare(strict_types=1);

namespace Modules\Cart\Contracts;

use Modules\Cart\Models\Cart;
use Modules\Cart\Models\CartLine;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;

interface CartServiceInterface
{
    public function getOrCreateActiveCart(CartContext $context): Cart;

    public function addLine(Cart $cart, CartLineItemData $itemData, ?int $expectedVersion = null): CartLine;

    public function updateQuantity(Cart $cart, int $lineId, CartQuantity $quantity, ?int $expectedVersion = null): CartLine;

    public function removeLine(Cart $cart, int $lineId, ?int $expectedVersion = null): bool;

    public function applyCoupon(Cart $cart, string $couponCode, ?int $expectedVersion = null): Cart;

    public function removeCoupon(Cart $cart, ?int $expectedVersion = null): Cart;

    public function clear(Cart $cart, ?int $expectedVersion = null): bool;

    public function mergeGuestCart(Cart $guestCart, Cart $customerCart): Cart;

    public function expire(Cart $cart): bool;
}
