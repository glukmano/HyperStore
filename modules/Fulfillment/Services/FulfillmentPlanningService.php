<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Services;

use Modules\Fulfillment\Contracts\FulfillmentPlanningServiceInterface;
use Modules\Fulfillment\Contracts\PackingStrategyInterface;
use Modules\Fulfillment\DTOs\FulfillmentGroup;
use Modules\Fulfillment\DTOs\FulfillmentItemLine;
use Modules\Fulfillment\DTOs\FulfillmentPlan;
use Modules\Fulfillment\Models\FulfillmentSourceConfiguration;
use Modules\Inventory\Contracts\InventoryAvailabilityServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Models\InventorySource;
use Modules\Shipping\ValueObjects\ShippingContext;

class FulfillmentPlanningService implements FulfillmentPlanningServiceInterface
{
    public function __construct(
        private readonly InventoryAvailabilityServiceInterface $availabilityService,
        private readonly PackingStrategyInterface $packingService
    ) {}

    /**
     * Executes pure read-only fulfillment planning with ZERO database mutations.
     */
    public function plan(int $tenantId, array $items, ShippingContext $context): FulfillmentPlan
    {
        $shippableItems = [];
        $nonPhysicalItems = [];

        foreach ($items as $item) {
            /** @var FulfillmentItemLine $item */
            if ($item->isShippable) {
                $shippableItems[] = $item;
            } else {
                $nonPhysicalItems[] = $item;
            }
        }

        $groups = [];

        // 1. Non-physical items group
        if (! empty($nonPhysicalItems)) {
            $groups[] = new FulfillmentGroup(
                groupKey: 'grp_non_physical_'.uniqid(),
                fulfillmentMode: 'non_physical',
                inventorySourceId: null,
                warehouseId: null,
                items: $nonPhysicalItems,
                packages: [],
                isShippable: false,
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

            // Fetch active sources sorted by priority
            $sources = InventorySource::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->orderBy('priority', 'desc')
                ->get();

            // A. Check for single source that can fulfill ALL shippable items
            $singleSource = null;
            foreach ($sources as $source) {
                /** @var InventorySource $source */
                if ($this->canSourceFulfillAll($source, $shippableItems, $invContext)) {
                    $singleSource = $source;
                    break;
                }
            }

            if ($singleSource !== null) {
                // All items fulfilled from this single source
                $packages = $this->packingService->pack($shippableItems, $singleSource->id);
                $mode = $this->resolveFulfillmentMode($tenantId, $singleSource->id);

                $groups[] = new FulfillmentGroup(
                    groupKey: 'grp_src_'.$singleSource->id,
                    fulfillmentMode: $mode,
                    inventorySourceId: $singleSource->id,
                    warehouseId: $singleSource->warehouse_id,
                    items: $shippableItems,
                    packages: $packages,
                    isShippable: true,
                    splitReason: null
                );
            } else {
                // B. Split required across multiple sources
                $allocatedSourceMap = []; // sourceId => items[]

                foreach ($shippableItems as $item) {
                    $allocated = false;
                    $avail = $this->availabilityService->check($item->productId, $item->variantId, $invContext);

                    foreach ($avail->sourceBreakdown as $breakdown) {
                        $srcId = $breakdown['source_id'];
                        $availQty = $breakdown['available'];
                        if (bccomp($availQty->toString(), (string) $item->quantity, 4) >= 0) {
                            $allocatedSourceMap[$srcId][] = $item;
                            $allocated = true;
                            break;
                        }
                    }

                    if (! $allocated) {
                        // Fallback to primary source even if backordered
                        $primarySource = $sources->first();
                        $primarySourceId = $primarySource ? $primarySource->id : 0;
                        $allocatedSourceMap[$primarySourceId][] = $item;
                    }
                }

                foreach ($allocatedSourceMap as $srcId => $allocatedItems) {
                    /** @var InventorySource|null $src */
                    $src = $sources->firstWhere('id', $srcId);
                    $packages = $this->packingService->pack($allocatedItems, $srcId > 0 ? $srcId : null);
                    $mode = $srcId > 0 ? $this->resolveFulfillmentMode($tenantId, $srcId) : 'own_stock';

                    $groups[] = new FulfillmentGroup(
                        groupKey: 'grp_src_'.$srcId.'_'.uniqid(),
                        fulfillmentMode: $mode,
                        inventorySourceId: $srcId > 0 ? $srcId : null,
                        warehouseId: $src?->warehouse_id,
                        items: $allocatedItems,
                        packages: $packages,
                        isShippable: true,
                        splitReason: 'Multi-source stock availability split'
                    );
                }
            }
        }

        $hasSplits = count(array_filter($groups, fn ($g) => $g->isShippable)) > 1;

        return new FulfillmentPlan(
            tenantId: $tenantId,
            groups: $groups,
            hasSplits: $hasSplits
        );
    }

    /**
     * @param  array<int, FulfillmentItemLine>  $items
     */
    private function canSourceFulfillAll(InventorySource $source, array $items, InventoryContext $context): bool
    {
        foreach ($items as $item) {
            /** @var FulfillmentItemLine $item */
            $avail = $this->availabilityService->check($item->productId, $item->variantId, $context);
            $sourceAvail = null;
            foreach ($avail->sourceBreakdown as $sb) {
                if ($sb['source_id'] === $source->id) {
                    $sourceAvail = $sb['available'];
                    break;
                }
            }

            if ($sourceAvail === null || bccomp($sourceAvail->toString(), (string) $item->quantity, 4) < 0) {
                return false;
            }
        }

        return true;
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
