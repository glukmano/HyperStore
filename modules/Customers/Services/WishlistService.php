<?php

declare(strict_types=1);

namespace Modules\Customers\Services;

use App\Core\Context\ContextManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

    public function defaultWishlistForSession(string $sessionId): Wishlist
    {
        $tenantId = (int) $this->contextManager->getTenant()->getId();

        return Wishlist::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'session_id' => $sessionId, 'is_default' => true],
            ['name' => 'Default'],
        );
    }

    public function defaultWishlistForIdentity(?User $user, ?string $sessionId): Wishlist
    {
        if ($user !== null) {
            return $this->defaultWishlistFor($user);
        }

        if ($sessionId === null) {
            throw new \InvalidArgumentException('Either an authenticated user or a session id is required.');
        }

        return $this->defaultWishlistForSession($sessionId);
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
     * Merges a guest session's wishlist into the authenticated user's
     * default wishlist on login — the same guest→auth merge shape Cart
     * already uses. Transactional and idempotent: `addItem()` is a
     * `firstOrCreate` against the (wishlist_id, product_id, variant_id)
     * unique constraint, so running this twice for the same guest session
     * never duplicates rows, and the guest wishlist is deleted only after
     * every item has been moved so a failure never loses data silently.
     */
    public function mergeGuestWishlist(User $user, string $guestSessionId): void
    {
        $tenantId = (int) $this->contextManager->getTenant()->getId();

        $guestWishlists = Wishlist::query()
            ->where('tenant_id', $tenantId)
            ->where('session_id', $guestSessionId)
            ->get();

        if ($guestWishlists->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($user, $guestWishlists): void {
            $userWishlist = $this->defaultWishlistFor($user);

            /** @var Wishlist $guestWishlist */
            foreach ($guestWishlists as $guestWishlist) {
                foreach ($guestWishlist->items as $item) {
                    /** @var WishlistItem $item */
                    $this->addItem($userWishlist, $item->product_id, $item->variant_id, $item->note);
                }

                $guestWishlist->delete();
            }
        });
    }

    public function generateShareToken(Wishlist $wishlist): string
    {
        $wishlist->visibility = 'shared';
        $wishlist->share_token = $wishlist->share_token ?? Str::random(48);
        $wishlist->save();

        return (string) $wishlist->share_token;
    }
}
