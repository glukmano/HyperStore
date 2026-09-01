<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Services;

use Modules\Fulfillment\Contracts\FulfillmentPlanningServiceInterface;
use Modules\Fulfillment\Contracts\PackingStrategyInterface;
use Modules\Fulfillment\DTOs\FulfillmentGroup;
use Modules\Fulfillment\DTOs\FulfillmentItemLine;
use Modules\Fulfillment\DTOs\FulfillmentPlan;
use Modules\Fulfillment\DTOs\FulfillmentReadiness;
use Modules\Fulfillment\Models\FulfillmentSourceConfiguration;
use Modules\Inventory\Contracts\InventorySourceQueryInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\DTOs\InventorySourceDTO;
use Modules\Inventory\DTOs\SourceAvailabilityDTO;
use Modules\Shipping\ValueObjects\ShippingContext;

class FulfillmentPlanningService implements FulfillmentPlanningServiceInterface
{
    public function __construct(
        private readonly InventorySourceQueryInterface $sourceQuery,
        private readonly PackingStrategyInterface $packingService
    ) {}

    /**
     * Executes pure read-only fulfillment planning with ZERO database mutations.
     *
     * Source selection strategy (deterministic):
     *  1. Eligible sources only (active, not stale, context-scoped).
     *  2. Prefer single source that can fulfill entire physical request.
     *  3. Minimize splits.
     *  4. Source priority DESC.
     *  5. Stable source ID/code tie-breaker.
     *
     * @param  array<int, FulfillmentItemLine>  $items
     */
    public function plan(int $tenantId, array $items, ShippingContext $context): FulfillmentPlan
    {
        $shippableItems = [];
        $nonPhysicalItems = [];

        foreach ($items as $item) {
            if ($item->isShippable) {
                $shippableItems[] = $item;
            } else {
                $nonPhysicalItems[] = $item;
            }
        }

        $groups = [];

        // 1. Non-physical items group — deterministic key
        if (! empty($nonPhysicalItems)) {
            $groups[] = new FulfillmentGroup(
                groupKey: 'non_physical:digital',
                fulfillmentMode: 'non_physical',
                inventorySourceId: null,
                warehouseId: null,
                items: $nonPhysicalItems,
                packages: [],
                isShippable: false,
                readiness: FulfillmentReadiness::NON_PHYSICAL,
                splitReason: null
            );
        }

        // 2. Shippable physical items
        if (! empty($shippableItems)) {
            $invContext = new InventoryContext(
                tenantId: $tenantId,
                storeId: $context->storeId,
                marketId: $context->marketId,
                channelId: $context->channelId
            );

            // Fetch eligible sources through Inventory contract (NO Eloquent here)
            $eligibleSources = $this->sourceQuery->getEligibleSources($invContext);

            if (empty($eligibleSources)) {
                // No eligible sources — all items unavailable
                foreach ($shippableItems as $item) {
                    $groups[] = new FulfillmentGroup(
                        groupKey: 'unavailable:no_sources',
                        fulfillmentMode: 'unavailable',
                        inventorySourceId: null,
                        warehouseId: null,
                        items: [$item],
                        packages: [],
                        isShippable: false,
                        readiness: FulfillmentReadiness::UNAVAILABLE,
                        splitReason: 'No eligible inventory sources for context'
                    );
                }
            } else {
                $newGroups = $this->allocateShippableItems($tenantId, $shippableItems, $eligibleSources, $invContext);
                $groups = array_merge($groups, $newGroups);
            }
        }

        $physicalGroups = array_filter($groups, fn ($g) => $g->isShippable);
        $hasSplits = count($physicalGroups) > 1;

        return new FulfillmentPlan(
            tenantId: $tenantId,
            groups: $groups,
            hasSplits: $hasSplits
        );
    }

    /**
     * Allocates shippable items across eligible sources using the deterministic 5-step strategy.
     *
     * @param  array<int, FulfillmentItemLine>  $items
     * @param  InventorySourceDTO[]  $sources  Already sorted by priority DESC, id ASC
     * @return array<int, FulfillmentGroup>
     */
    private function allocateShippableItems(
        int $tenantId,
        array $items,
        array $sources,
        InventoryContext $invContext
    ): array {
        // Step 1: Build per-source availability for all items
        // sourceId => productKey => SourceAvailabilityDTO
        /** @var array<int, array<string, SourceAvailabilityDTO>> $availability */
        $availability = [];
        foreach ($sources as $source) {
            $availability[$source->id] = [];
            foreach ($items as $item) {
                $key = $item->productId.'_'.($item->variantId ?? '0');
                $avail = $this->sourceQuery->checkSourceAvailability(
                    $item->productId,
                    $item->variantId,
                    $source->id,
                    $invContext
                );
                $availability[$source->id][$key] = $avail;
            }
        }

        // Step 2: Try single-source fulfillment (prefer no split)
        foreach ($sources as $source) {
            if ($this->sourceCanFulfillAll($source->id, $items, $availability)) {
                $packResult = $this->packingService->pack($items, $source->id);
                $mode = $this->resolveFulfillmentMode($tenantId, $source->id);

                $readiness = FulfillmentReadiness::READY;
                foreach ($items as $it) {
                    $k = $it->productId.'_'.($it->variantId ?? '0');
                    $av = $availability[$source->id][$k] ?? null;
                    if ($av !== null) {
                        if ($av->readiness === SourceAvailabilityDTO::BACKORDERED && $readiness === FulfillmentReadiness::READY) {
                            $readiness = FulfillmentReadiness::BACKORDERED;
                        } elseif ($av->readiness === SourceAvailabilityDTO::PREORDER) {
                            $readiness = FulfillmentReadiness::PREORDER;
                        }
                    }
                }

                return [new FulfillmentGroup(
                    groupKey: 'source:'.$source->id,
                    fulfillmentMode: $mode,
                    inventorySourceId: $source->id,
                    warehouseId: $source->warehouseId,
                    items: $items,
                    packages: is_array($packResult) ? $packResult : $packResult->packages,
                    isShippable: true,
                    readiness: $readiness,
                    splitReason: null
                )];
            }
        }

        // Step 3: Split required — allocate quantities across sources
        // Each line item quantity may be split across multiple sources
        return $this->splitAllocate($tenantId, $items, $sources, $availability, $invContext);
    }

    /**
     * Check if a given source can fulfill ALL items at their requested quantities.
     *
     * @param  array<int, FulfillmentItemLine>  $items
     * @param  array<int, array<string, SourceAvailabilityDTO>>  $availability
     */
    private function sourceCanFulfillAll(int $sourceId, array $items, array $availability): bool
    {
        foreach ($items as $item) {
            $key = $item->productId.'_'.($item->variantId ?? '0');
            $avail = $availability[$sourceId][$key] ?? null;
            if ($avail === null || ! $avail->canFulfillQuantity($item->quantity)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Split-allocate quantities across sources.
     * A single line quantity can be split across two or more sources.
     *
     * @param  array<int, FulfillmentItemLine>  $items
     * @param  InventorySourceDTO[]  $sources
     * @param  array<int, array<string, SourceAvailabilityDTO>>  $availability
     * @return array<int, FulfillmentGroup>
     */
    private function splitAllocate(
        int $tenantId,
        array $items,
        array $sources,
        array $availability,
        InventoryContext $invContext
    ): array {
        // sourceId => [ FulfillmentItemLine (partial qty) ]
        /** @var array<int, list<FulfillmentItemLine>> $sourceAllocations */
        $sourceAllocations = [];
        // Items that could not be fulfilled by any source
        $unavailableItems = [];

        foreach ($items as $item) {
            $key = $item->productId.'_'.($item->variantId ?? '0');
            $remainingQty = $item->quantity;

            foreach ($sources as $source) {
                if ($remainingQty <= 0) {
                    break;
                }
                $avail = $availability[$source->id][$key] ?? null;
                if ($avail === null) {
                    continue;
                }

                if ($avail->readiness === SourceAvailabilityDTO::BACKORDERED || $avail->readiness === SourceAvailabilityDTO::PREORDER) {
                    $allocateQty = $remainingQty;
                } elseif ($avail->isReady()) {
                    $availableAtSource = (int) floor((float) $avail->available->toString());
                    if ($availableAtSource <= 0) {
                        continue;
                    }
                    $allocateQty = min($remainingQty, $availableAtSource);
                } else {
                    continue;
                }
                $allocatedLine = new FulfillmentItemLine(
                    productId: $item->productId,
                    variantId: $item->variantId,
                    quantity: $allocateQty,
                    unitPrice: $item->unitPrice,
                    unitWeight: $item->unitWeight,
                    isShippable: true
                );
                $sourceAllocations[$source->id][] = $allocatedLine;
                $remainingQty -= $allocateQty;
            }

            if ($remainingQty > 0) {
                // Remaining quantity cannot be fulfilled
                $unavailableItem = new FulfillmentItemLine(
                    productId: $item->productId,
                    variantId: $item->variantId,
                    quantity: $remainingQty,
                    unitPrice: $item->unitPrice,
                    unitWeight: $item->unitWeight,
                    isShippable: false
                );
                $unavailableItems[] = $unavailableItem;
            }
        }

        $groups = [];

        // Build groups for each source allocation
        foreach ($sourceAllocations as $sourceId => $allocatedLines) {
            $sourceDto = null;
            foreach ($sources as $s) {
                if ($s->id === $sourceId) {
                    $sourceDto = $s;
                    break;
                }
            }
            $packResult = $this->packingService->pack($allocatedLines, $sourceId);
            $mode = $this->resolveFulfillmentMode($tenantId, $sourceId);
            $groups[] = new FulfillmentGroup(
                groupKey: 'source:'.$sourceId,
                fulfillmentMode: $mode,
                inventorySourceId: $sourceId,
                warehouseId: $sourceDto?->warehouseId,
                items: $allocatedLines,
                packages: is_array($packResult) ? $packResult : $packResult->packages,
                isShippable: true,
                readiness: FulfillmentReadiness::READY,
                splitReason: 'Multi-source quantity split'
            );
        }

        // Build unavailable group
        if (! empty($unavailableItems)) {
            $groups[] = new FulfillmentGroup(
                groupKey: 'unavailable:no_stock',
                fulfillmentMode: 'unavailable',
                inventorySourceId: null,
                warehouseId: null,
                items: $unavailableItems,
                packages: [],
                isShippable: false,
                readiness: FulfillmentReadiness::UNAVAILABLE,
                splitReason: 'Insufficient stock across all eligible sources'
            );
        }

        return $groups;
    }

    private function resolveFulfillmentMode(int $tenantId, int $inventorySourceId): string
    {
        $cfg = FulfillmentSourceConfiguration::query()
            ->where('tenant_id', $tenantId)
            ->where('inventory_source_id', $inventorySourceId)
            ->first();

        return $cfg ? $cfg->fulfillment_mode : 'own_stock';
    }
}
