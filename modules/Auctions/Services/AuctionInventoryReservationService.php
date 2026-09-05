<?php

declare(strict_types=1);

namespace Modules\Auctions\Services;

use Modules\Auctions\Models\Auction;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\ValueObjects\Quantity;

/**
 * Owner Delta §7: the auctioned lot is reserved through the EXISTING
 * Inventory reservation semantics — reserved at Auction ACTIVATION (not at
 * close), so it cannot simultaneously be sold/consumed elsewhere while
 * bidding is open. The winner's system-generated Checkout hands this exact
 * reservation off to Order-adoption (`CheckoutOrchestrator::reserveInventory()`
 * reuses the key rather than reserving a second time; `OrderCreationService`'s
 * existing adopt() loop then converts it to ORDER ownership like any normal
 * Checkout) — never a parallel/duplicated stock-tracking mechanism.
 */
final class AuctionInventoryReservationService
{
    public function __construct(
        private readonly InventoryReservationServiceInterface $reservationService,
    ) {}

    public function reserveAtActivation(Auction $auction): bool
    {
        /** @var StockItem|null $stockItem */
        $stockItem = StockItem::where('tenant_id', $auction->tenant_id)
            ->where('product_id', $auction->product_id)
            ->whereNull('product_variant_id')
            ->first();

        if ($stockItem === null) {
            // Not physically inventory-tracked — nothing to reserve.
            return false;
        }

        $key = "auction:{$auction->uuid}";
        $ttlMinutes = (int) ceil($auction->starts_at->diffInMinutes($auction->ends_at)) + $auction->winner_payment_window_minutes + 60;

        $context = new InventoryContext(tenantId: $auction->tenant_id, storeId: $auction->store_id);

        $result = $this->reservationService->reserve(
            tenantId: $auction->tenant_id,
            reservationKey: $key,
            productId: $auction->product_id,
            variantId: null,
            requestedQuantity: Quantity::fromInteger(1),
            context: $context,
            ttlMinutes: $ttlMinutes,
            idempotencyKey: $key,
        );

        if (! $result->isSuccess) {
            return false;
        }

        $auction->update(['inventory_reservation_key' => $key]);

        return true;
    }

    public function release(Auction $auction): void
    {
        if ($auction->inventory_reservation_key === null) {
            return;
        }

        $this->reservationService->release($auction->tenant_id, $auction->inventory_reservation_key);
    }
}
