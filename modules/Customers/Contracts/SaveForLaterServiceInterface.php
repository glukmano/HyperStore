<?php

declare(strict_types=1);

namespace Modules\Customers\Contracts;

use App\Models\User;
use Modules\Cart\Models\Cart;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Customers\Models\SavedForLaterItem;

/**
 * The one explicit two-way seam between Cart and Customers (approved plan
 * §8): Cart calls this to park a line as "saved for later" without reaching
 * into Customers' tables directly. moveToCart() is the reverse direction —
 * it calls back into Cart's own CartServiceInterface internally, so Cart's
 * storefront callers never need to touch Customers' tables either.
 */
interface SaveForLaterServiceInterface
{
    public function saveForLater(User $user, int $productId, ?int $variantId, int $quantity, int $unitPriceMinorSnapshot, string $currency): SavedForLaterItem;

    public function moveToCart(SavedForLaterItem $item, CartContext $cartContext): Cart;

    public function removeSavedItem(SavedForLaterItem $item): void;
}
