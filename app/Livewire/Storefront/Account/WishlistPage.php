<?php

declare(strict_types=1);

namespace App\Livewire\Storefront\Account;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Customers\Models\Wishlist;
use Modules\Customers\Services\WishlistService;

class WishlistPage extends Component
{
    public ?string $shareToken = null;

    public function mount(?string $shareToken = null): void
    {
        $this->shareToken = $shareToken;
    }

    public function removeItem(int $productId, ?int $variantId, WishlistService $wishlistService): void
    {
        if ($this->isReadOnlySharedView()) {
            return;
        }

        $wishlist = $this->wishlist($wishlistService);
        $wishlistService->removeItem($wishlist, $productId, $variantId);
    }

    public function generateShareLink(WishlistService $wishlistService): void
    {
        if ($this->isReadOnlySharedView()) {
            return;
        }

        $wishlist = $this->wishlist($wishlistService);
        $wishlistService->generateShareToken($wishlist);
    }

    public function render(WishlistService $wishlistService): View
    {
        $wishlist = $this->isReadOnlySharedView()
            ? Wishlist::query()->where('share_token', $this->shareToken)->where('visibility', 'shared')->firstOrFail()
            : $this->wishlist($wishlistService);

        $wishlist->load('items.product.translations', 'items.variant');

        return view('theme::pages.account.wishlist', [
            'wishlist' => $wishlist,
            'readOnly' => $this->isReadOnlySharedView(),
        ])->layout('theme::layouts.app', ['title' => __('Wishlist')]);
    }

    private function isReadOnlySharedView(): bool
    {
        return $this->shareToken !== null;
    }

    private function wishlist(WishlistService $wishlistService): Wishlist
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $wishlistService->defaultWishlistForIdentity($user, $user === null ? session()->getId() : null);
    }
}
