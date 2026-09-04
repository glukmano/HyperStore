<?php

declare(strict_types=1);

namespace Modules\Customers\Services;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Support\Str;
use Modules\Customers\Models\Wishlist;
use Modules\Customers\Models\WishlistItem;

final class WishlistService
{
    public function __construct(
        private readonly ContextManager $contextManager,
    ) {}

    public function defaultWishlistFor(User $user): Wishlist
    {
        $tenantId = (int) $this->contextManager->getTenant()->getId();

        return Wishlist::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $user->id, 'is_default' => true],
            ['name' => 'Default'],
        );
    }

    public function addItem(Wishlist $wishlist, int $productId, ?int $variantId = null, ?string $note = null): WishlistItem
    {
        return WishlistItem::query()->firstOrCreate(
            ['wishlist_id' => $wishlist->id, 'product_id' => $productId, 'variant_id' => $variantId],
            ['note' => $note, 'added_at' => now()],
        );
    }

    public function removeItem(Wishlist $wishlist, int $productId, ?int $variantId = null): void
    {
        WishlistItem::query()
            ->where('wishlist_id', $wishlist->id)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->delete();
    }

    /**
     * Merges a guest's session-held wishlist product IDs into the
     * authenticated user's default wishlist on login — the same guest→auth
     * merge shape Cart already uses elsewhere in this codebase.
     *
     * @param  list<int>  $guestProductIds
     */
    public function mergeGuestWishlist(User $user, array $guestProductIds): void
    {
        if ($guestProductIds === []) {
            return;
        }

        $wishlist = $this->defaultWishlistFor($user);

        foreach ($guestProductIds as $productId) {
            $this->addItem($wishlist, $productId);
        }
    }

    public function generateShareToken(Wishlist $wishlist): string
    {
        $wishlist->visibility = 'shared';
        $wishlist->share_token = $wishlist->share_token ?? Str::random(48);
        $wishlist->save();

        return (string) $wishlist->share_token;
    }
}
