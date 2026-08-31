<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\DTOs\InventorySourceDTO;
use Modules\Inventory\DTOs\SourceAvailabilityDTO;

/**
 * Read-only contract for Fulfillment to query eligible InventorySources
 * without coupling to Inventory Eloquent models.
 */
interface InventorySourceQueryInterface
{
    /**
     * Returns active, eligible sources for the given context,
     * sorted by priority DESC, then source id ASC (stable).
     * Excludes stale sources.
     *
     * @return InventorySourceDTO[]
     */
    public function getEligibleSources(InventoryContext $context): array;

    /**
     * Returns per-source availability for a specific product/variant
     * at the given source, respecting backorder/preorder policies.
     */
    public function checkSourceAvailability(
        int $productId,
        ?int $variantId,
        int $sourceId,
        InventoryContext $context
    ): SourceAvailabilityDTO;
}
