<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Livewire\Storefront\Concerns\BuildsCartContext;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Customers\Models\GiftRegistry;
use Modules\Customers\Models\GiftRegistryItem;

class GiftRegistryPublicPage extends Component
{
    use BuildsCartContext;

    public string $shareToken;

    public function mount(string $shareToken): void
    {
        $this->shareToken = $shareToken;
    }

    /**
     * The one write site for gift-registry attribution: the cart line's
     * `customizations` carries `gift_registry_item_id`, which
     * OrderSnapshotValidator already forwards into
     * OrderItem.customization_metadata_snapshot at order-creation time — no
     * separate metadata channel invented, reusing the existing
     * customizations pass-through Cart -> Checkout -> Order already has.
     */
    public function buyItem(int $registryItemId, CartServiceInterface $cartService): void
    {
        $item = GiftRegistryItem::query()
            ->whereHas('registry', fn ($q) => $q->where('share_token', $this->shareToken)->where('visibility', '!=', 'private'))
            ->where('id', $registryItemId)
            ->first();

        if ($item === null || $item->isFullyPurchased()) {
            return;
        }

        $cartContext = $this->buildCartContext();
        if ($cartContext === null) {
            session()->flash('error', __('Unable to add this gift to your cart right now.'));

            return;
        }

        $cart = $cartService->getOrCreateActiveCart($cartContext);

        $cartService->addLine($cart, new CartLineItemData(
            productId: $item->product_id,
            variantId: $item->variant_id,
            quantity: CartQuantity::fromInt(1),
            customizations: ['gift_registry_item_id' => $item->id],
        ));

        session()->flash('success', __('Added to your cart.'));
        $this->redirect(route('storefront.cart'), navigate: true);
    }

    public function render(): View
    {
        $registry = GiftRegistry::query()
            ->where('share_token', $this->shareToken)
            ->where('visibility', '!=', 'private')
            ->with('items.product.translations')
            ->first();

        $title = $registry !== null ? $registry->title : __('Gift Registry');

        return view('theme::pages.gift-registry-public', ['registry' => $registry])
            ->layout('theme::layouts.app', ['title' => $title]);
    }
}
