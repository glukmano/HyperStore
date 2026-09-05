<?php

declare(strict_types=1);

namespace Modules\Auctions\Services;

use App\Core\Stores\Models\Store;
use Illuminate\Support\Facades\DB;
use Modules\Auctions\Enums\AuctionStatus;
use Modules\Auctions\Models\Auction;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Customers\Models\CustomerProfile;

/**
 * Owner Delta §7: builds the ONE and ONLY Checkout the winning bidder may
 * complete — bound to auction_id, the winning bid snapshot (via the Cart
 * line's own metadata, read directly by CheckoutPricingOrchestrator/
 * OrderCreationService — no second pricing/order engine), and the
 * already-held Inventory reservation (handed off, never re-reserved).
 */
final class AuctionSettlementService
{
    public function __construct(
        private readonly CartServiceInterface $cartService,
        private readonly CheckoutOrchestratorInterface $checkoutOrchestrator,
        private readonly AuctionInventoryReservationService $inventoryReservationService,
    ) {}

    public function closeAuction(Auction $auction): void
    {
        DB::transaction(function () use ($auction): void {
            /** @var Auction $locked */
            $locked = Auction::where('id', $auction->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AuctionStatus::Active) {
                return;
            }

            if ($locked->current_bid_id === null || ! $locked->reserveMet()) {
                $this->inventoryReservationService->release($locked);
                $locked->update(['status' => AuctionStatus::Cancelled]);

                return;
            }

            $locked->update(['status' => AuctionStatus::Ended]);
        });
    }

    public function createWinnerCheckout(Auction $auction): CheckoutSession
    {
        $auction->refresh();

        $existing = CheckoutSession::where('auction_id', $auction->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $winnerProfile = CustomerProfile::findOrFail($auction->current_bidder_customer_profile_id);

        /** @var Store $store */
        $store = Store::findOrFail($auction->store_id);
        $market = $store->defaultMarket();
        $channel = $store->channels()->wherePivot('is_active', true)->first();

        $context = new CartContext(
            tenantId: $auction->tenant_id,
            storeId: $auction->store_id,
            marketId: $market !== null ? (int) $market->id : 0,
            channelId: $channel !== null ? (int) $channel->id : 0,
            currency: $auction->currency,
            userId: (int) $winnerProfile->user_id,
            guestToken: null,
        );

        $cart = $this->cartService->getOrCreateActiveCart($context);

        $cartLine = $this->cartService->addLine($cart, new CartLineItemData(
            productId: (int) $auction->product_id,
            variantId: null,
            quantity: CartQuantity::fromInt(1),
            metadata: [
                'auction_id' => $auction->id,
                'winning_bid_id' => $auction->current_bid_id,
                'winning_bid_amount_minor' => $auction->current_price_minor,
                'auction_currency_snapshot' => $auction->currency,
                'reserve_met' => $auction->reserveMet(),
            ],
        ));
        $cartLine->update([
            'display_unit_price_minor' => $auction->current_price_minor,
            'display_currency' => $auction->currency,
        ]);

        $session = $this->checkoutOrchestrator->createFromCart($cart);
        $session->update([
            'auction_id' => $auction->id,
            'reservation_references' => $auction->inventory_reservation_key !== null
                ? [['reservation_key' => $auction->inventory_reservation_key]]
                : [],
        ]);

        return $session->refresh();
    }
}
