<?php

declare(strict_types=1);

namespace Modules\Customers\Services;

use App\Core\Context\ContextManager;
use App\Models\User;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\Models\Cart;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Customers\Contracts\SaveForLaterServiceInterface;
use Modules\Customers\Models\SavedForLaterItem;

final class SaveForLaterService implements SaveForLaterServiceInterface
{
    public function __construct(
        private readonly ContextManager $contextManager,
        private readonly CartServiceInterface $cartService,
    ) {}

    public function saveForLater(User $user, int $productId, ?int $variantId, int $quantity, int $unitPriceMinorSnapshot): SavedForLaterItem
    {
        return SavedForLaterItem::query()->create([
            'tenant_id' => (int) $this->contextManager->getTenant()->getId(),
            'user_id' => $user->id,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'quantity' => $quantity,
            'unit_price_minor_snapshot' => $unitPriceMinorSnapshot,
            'added_at' => now(),
        ]);
    }

    /**
     * Moves a saved item back into the user's active cart. Price is always
     * re-resolved by Cart/Pricing when the line is added — the snapshot on
     * SavedForLaterItem is display-only ("price when saved"), never trusted
     * for the live cart line.
     */
    public function moveToCart(SavedForLaterItem $item, CartContext $cartContext): Cart
    {
        $cart = $this->cartService->getOrCreateActiveCart($cartContext);

        $this->cartService->addLine($cart, new CartLineItemData(
            productId: $item->product_id,
            variantId: $item->variant_id,
            quantity: CartQuantity::fromInt($item->quantity),
        ));

        $item->delete();

        return $cart;
    }

    public function removeSavedItem(SavedForLaterItem $item): void
    {
        $item->delete();
    }
}
