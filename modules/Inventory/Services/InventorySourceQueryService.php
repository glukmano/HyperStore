<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use DateTimeImmutable;
use Modules\Inventory\Contracts\ExternalStockProviderInterface;
use Modules\Inventory\Contracts\InventorySourceQueryInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\DTOs\InventorySourceDTO;
use Modules\Inventory\DTOs\SourceAvailabilityDTO;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\ValueObjects\Quantity;

/**
 * Read-only service implementing InventorySourceQueryInterface.
 *
 * This is the ONLY approved path for Fulfillment to query inventory source
 * eligibility without coupling to Inventory Eloquent models.
 */
class InventorySourceQueryService implements InventorySourceQueryInterface
{
    public function __construct(
        private readonly InventorySourceEligibilityService $eligibilityService,
        private readonly ?ExternalStockProviderInterface $externalStockProvider = null,
    ) {}

    /**
     * @return InventorySourceDTO[]
     */
    public function getEligibleSources(InventoryContext $context): array
    {
        $eligibleIds = $this->eligibilityService->getEligibleSourceIds($context);
        if (empty($eligibleIds)) {
            return [];
        }

        $sources = InventorySource::query()
            ->where('tenant_id', $context->tenantId)
            ->whereIn('id', $eligibleIds)
            ->with(['stores', 'markets', 'channels'])
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        $dtos = [];
        foreach ($sources as $source) {
            /** @var InventorySource $source */
            $lastSync = $source->last_synced_at !== null
                ? DateTimeImmutable::createFromInterface($source->last_synced_at)
                : null;

            $dtos[] = new InventorySourceDTO(
                id: $source->id,
                tenantId: $source->tenant_id,
                warehouseId: $source->warehouse_id,
                sourceType: $source->source_type,
                code: $source->code,
                name: $source->name,
                status: $source->status,
                priority: $source->priority,
                storeIds: $source->stores->pluck('id')->all(),
                marketIds: $source->markets->pluck('id')->all(),
                channelIds: $source->channels->pluck('id')->all(),
                isStale: $source->isStale(),
                lastSyncedAt: $lastSync,
            );
        }

        return $dtos;
    }

    public function checkSourceAvailability(
        int $productId,
        ?int $variantId,
        int $sourceId,
        InventoryContext $context
    ): SourceAvailabilityDTO {
        // Validate source belongs to tenant and is eligible
        /** @var InventorySource|null $source */
        $source = InventorySource::query()
            ->where('tenant_id', $context->tenantId)
            ->where('id', $sourceId)
            ->first();

        if ($source === null || $source->status !== 'active') {
            return new SourceAvailabilityDTO(
                sourceId: $sourceId,
                available: Quantity::zero(),
                onHand: Quantity::zero(),
                reserved: Quantity::zero(),
                readiness: SourceAvailabilityDTO::UNAVAILABLE,
            );
        }

        if ($source->isStale()) {
            return new SourceAvailabilityDTO(
                sourceId: $sourceId,
                available: Quantity::zero(),
                onHand: Quantity::zero(),
                reserved: Quantity::zero(),
                readiness: SourceAvailabilityDTO::UNAVAILABLE,
            );
        }

        // External supplier stock (ADR-0124): normalized, read-only availability —
        // never written into stock_items.on_hand. Fails closed on any resolution failure.
        if ($source->source_type === 'supplier') {
            if ($this->externalStockProvider === null) {
                return new SourceAvailabilityDTO(
                    sourceId: $sourceId,
                    available: Quantity::zero(),
                    onHand: Quantity::zero(),
                    reserved: Quantity::zero(),
                    readiness: SourceAvailabilityDTO::UNAVAILABLE,
                );
            }

            $snapshot = $this->externalStockProvider->getAvailability($source);
            if ($snapshot->unavailable || $snapshot->available === null || $snapshot->available->isZero()) {
                return new SourceAvailabilityDTO(
                    sourceId: $sourceId,
                    available: Quantity::zero(),
                    onHand: Quantity::zero(),
                    reserved: Quantity::zero(),
                    readiness: SourceAvailabilityDTO::UNAVAILABLE,
                );
            }

            return new SourceAvailabilityDTO(
                sourceId: $sourceId,
                available: $snapshot->available,
                onHand: Quantity::zero(),
                reserved: Quantity::zero(),
                readiness: SourceAvailabilityDTO::READY,
            );
        }

        /** @var StockItem|null $stockItem */
        $stockItem = StockItem::query()
            ->where('tenant_id', $context->tenantId)
            ->where('inventory_source_id', $sourceId)
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->with('product')
            ->first();

        if ($stockItem === null) {
            return new SourceAvailabilityDTO(
                sourceId: $sourceId,
                available: Quantity::zero(),
                onHand: Quantity::zero(),
                reserved: Quantity::zero(),
                readiness: SourceAvailabilityDTO::UNAVAILABLE,
            );
        }

        $onHand = Quantity::fromString((string) ($stockItem->on_hand ?? 0));
        $reserved = Quantity::fromString((string) ($stockItem->reserved ?? 0));
        $ats = $stockItem->getAvailableToSellQuantity();
        $hasStock = bccomp($ats->toString(), '0', 4) > 0;

        // Determine readiness
        $backorderMode = $stockItem->backorder_mode ?? 'deny';
        // Preorder readiness is Catalog-owned truth (Product.product_type), never an Inventory-owned flag
        // (ADR-0127) — Inventory does not duplicate or re-derive commercial product-type ownership.
        $isPreorder = $stockItem->product?->product_type === 'preorder';

        if ($hasStock) {
            $readiness = SourceAvailabilityDTO::READY;
        } elseif ($isPreorder) {
            $readiness = SourceAvailabilityDTO::PREORDER;
        } elseif ($backorderMode === 'allow') {
            $readiness = SourceAvailabilityDTO::BACKORDERED;
        } else {
            $readiness = SourceAvailabilityDTO::UNAVAILABLE;
        }

        return new SourceAvailabilityDTO(
            sourceId: $sourceId,
            available: $ats,
            onHand: $onHand,
            reserved: $reserved,
            readiness: $readiness,
        );
    }
}
