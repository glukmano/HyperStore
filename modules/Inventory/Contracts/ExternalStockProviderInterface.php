<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\DTOs\ExternalStockSnapshotDTO;
use Modules\Inventory\Models\InventorySource;

/**
 * Read-only port completing ADR-0048's deferred external-stock seam (ADR-0124).
 *
 * Implementations resolve an InventorySource with source_type='supplier' (or, for a
 * future provider, 'vendor') to a live external availability snapshot. Inventory
 * never imports the concrete
 * provider's Eloquent models (e.g. Dropshipping's Supplier) — the dependency runs
 * one way only, from the provider's own module into this interface.
 *
 * The returned snapshot is READ-ONLY and MUST NEVER be written into
 * stock_items.on_hand — see ExternalStockSnapshotDTO.
 */
interface ExternalStockProviderInterface
{
    public function getAvailability(InventorySource $source): ExternalStockSnapshotDTO;
}
