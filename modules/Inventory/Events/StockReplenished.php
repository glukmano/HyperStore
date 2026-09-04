<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched by InventoryAdjustmentService, edge-triggered on the exact
 * <=0 -> >0 available-to-sell transition for one StockItem/InventorySource
 * — never on every adjustment like LowStockDetected/OutOfStockDetected are.
 * The transition-edge computation lives inside Inventory (the sole owner of
 * availability semantics), never reverse-engineered by a listener from raw
 * movement deltas.
 *
 * Fires at inventory-source granularity, not per eligible Store — resolving
 * which Stores a source is eligible for is InventoryEligibilityService's
 * concern and is intentionally not duplicated here (Phase-17 scope note:
 * Customer Engagement's back-in-stock listener matches subscriptions by
 * tenant+product+variant only, not the exact eligible-store set).
 */
class StockReplenished
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $inventorySourceId,
        public readonly int $productId,
        public readonly ?int $variantId,
        public readonly string $previousAvailableQty,
        public readonly string $newAvailableQty,
    ) {}
}
