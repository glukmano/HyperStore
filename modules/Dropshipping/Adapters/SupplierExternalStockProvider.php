<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Adapters;

use DateTimeImmutable;
use Modules\Dropshipping\Models\Supplier;
use Modules\Dropshipping\Models\SupplierOffer;
use Modules\Inventory\Contracts\ExternalStockProviderInterface;
use Modules\Inventory\DTOs\ExternalStockSnapshotDTO;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\ValueObjects\Quantity;

/**
 * Dropshipping-owned implementation of Inventory's ExternalStockProviderInterface
 * (ADR-0124, completing ADR-0048). Dependency direction is Dropshipping -> Inventory
 * interface only — Inventory never imports Supplier/SupplierOffer.
 *
 * external_reference format: "supplier_offer:{SupplierOffer.id}".
 *
 * Read-only: never writes to stock_items.on_hand. Fails closed (readiness/availability
 * = unavailable) on any resolution failure, staleness, inactive Supplier, or scope
 * authorization failure — never assumed-available.
 */
final class SupplierExternalStockProvider implements ExternalStockProviderInterface
{
    public function getAvailability(InventorySource $source): ExternalStockSnapshotDTO
    {
        if ($source->source_type !== 'supplier') {
            return ExternalStockSnapshotDTO::unavailable();
        }

        $reference = (string) ($source->external_reference ?? '');
        if (! str_starts_with($reference, 'supplier_offer:')) {
            return ExternalStockSnapshotDTO::unavailable();
        }

        $offerId = (int) substr($reference, strlen('supplier_offer:'));
        if ($offerId <= 0) {
            return ExternalStockSnapshotDTO::unavailable();
        }

        /** @var SupplierOffer|null $offer */
        $offer = SupplierOffer::query()->where('id', $offerId)->first();
        if ($offer === null || ! $offer->is_available) {
            return ExternalStockSnapshotDTO::unavailable();
        }

        /** @var Supplier|null $supplier */
        $supplier = Supplier::query()->where('id', $offer->supplier_id)->first();
        if ($supplier === null || $supplier->status !== 'active') {
            return ExternalStockSnapshotDTO::unavailable();
        }

        if (! $this->isSupplierAuthorizedForTenant($supplier, $source->tenant_id)) {
            return ExternalStockSnapshotDTO::unavailable();
        }

        if ($source->isStale()) {
            return ExternalStockSnapshotDTO::unavailable();
        }

        return ExternalStockSnapshotDTO::of(
            Quantity::fromString((string) $offer->stock_quantity),
            $source->last_synced_at !== null ? DateTimeImmutable::createFromInterface($source->last_synced_at) : null,
        );
    }

    /**
     * Mirrors the exact scope-authorization pattern established in
     * DropshipOrderOrchestrator::createPurchaseOrderForFulfillment().
     */
    private function isSupplierAuthorizedForTenant(Supplier $supplier, int $tenantId): bool
    {
        if ($supplier->isPlatform()) {
            return $supplier->tenantAccesses()
                ->where('tenant_id', $tenantId)
                ->where('is_enabled', true)
                ->exists();
        }

        if ($supplier->isTenant() || $supplier->isPrivateVendor()) {
            return $supplier->tenant_id === $tenantId;
        }

        return false;
    }
}
