<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use InvalidArgumentException;
use Modules\Inventory\Models\Warehouse;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Exceptions\VendorOperationalStatusException;
use Modules\Marketplace\Models\Vendor;

/**
 * Gates NEW mutating Inventory operations (transfer create/dispatch, adjustment)
 * against a vendor-owned Warehouse on the Vendor's operational status (ADR-0123).
 * Reuses the exact suspension-check pattern established across Marketplace services
 * (VendorListingCreationService, VendorPayableSubledgerService, PayoutService).
 *
 * Historical stock/movement visibility is never gated — InventoryMovement is
 * already immutable, so this guard is only invoked on write paths.
 */
final class WarehouseVendorAuthorizationGuard
{
    public static function assertWarehouseOperable(?Warehouse $warehouse): void
    {
        if ($warehouse === null || $warehouse->ownership_type !== 'vendor' || $warehouse->vendor_id === null) {
            return;
        }

        /** @var Vendor|null $vendor */
        $vendor = Vendor::query()->where('id', $warehouse->vendor_id)->lockForUpdate()->first();

        if ($vendor === null) {
            throw new InvalidArgumentException("Warehouse [{$warehouse->id}] references a non-existent Vendor [{$warehouse->vendor_id}].");
        }

        if ($vendor->operational_status !== VendorOperationalStatus::Active) {
            throw VendorOperationalStatusException::vendorNotActive($vendor->uuid, $vendor->operational_status->value);
        }
    }
}
