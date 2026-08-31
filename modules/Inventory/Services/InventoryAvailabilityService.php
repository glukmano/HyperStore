<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Modules\Catalog\Contracts\ProductTypeRegistryInterface;
use Modules\Catalog\Models\Product;
use Modules\Inventory\Contracts\InventoryAvailabilityServiceInterface;
use Modules\Inventory\DTOs\AvailabilityResultDTO;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\ValueObjects\Quantity;

class InventoryAvailabilityService implements InventoryAvailabilityServiceInterface
{
    public function __construct(
        private readonly ProductTypeRegistryInterface $productTypeRegistry,
        private readonly InventorySourceEligibilityService $eligibilityService
    ) {}

    public function check(int $productId, ?int $variantId, InventoryContext $context): AvailabilityResultDTO
    {
        /** @var Product|null $product */
        $product = Product::query()->where('tenant_id', $context->tenantId)->find($productId);
        if ($product === null) {
            return new AvailabilityResultDTO(
                productId: $productId,
                variantId: $variantId,
                availableQuantity: Quantity::zero(),
                isInStock: false,
                isBackorderable: false,
                isLowStock: false,
                stockStatus: 'out_of_stock',
                sourceBreakdown: []
            );
        }

        // 1. Check Product Type Capability
        $productType = $this->productTypeRegistry->get($product->product_type);
        if (! $productType->supportsInventory()) {
            return new AvailabilityResultDTO(
                productId: $productId,
                variantId: $variantId,
                availableQuantity: Quantity::fromString('9999999.0000'),
                isInStock: true,
                isBackorderable: true,
                isLowStock: false,
                stockStatus: 'untracked',
                sourceBreakdown: []
            );
        }

        // 2. Fetch Eligible Inventory Sources via shared service
        $eligibleSourceIds = $this->eligibilityService->getEligibleSourceIds($context);

        // 3. Fetch StockItems
        $stockItems = StockItem::query()
            ->where('tenant_id', $context->tenantId)
            ->whereIn('inventory_source_id', $eligibleSourceIds)
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->with('inventorySource')
            ->get();

        $totalAvailable = Quantity::zero();
        $sourceBreakdown = [];
        $isBackorderable = false;
        $isLowStock = false;

        foreach ($stockItems as $item) {
            $ats = $item->getAvailableToSellQuantity();
            $totalAvailable = $totalAvailable->add($ats);

            $srcName = $item->inventorySource instanceof InventorySource ? $item->inventorySource->name : 'Source';
            $sourceBreakdown[] = [
                'source_id' => $item->inventory_source_id,
                'source_name' => $srcName,
                'available' => $ats,
            ];

            if ($item->backorder_mode === 'allow' || $item->backorder_mode === 'allow_with_limit') {
                $isBackorderable = true;
            }

            if ($item->low_stock_threshold !== null) {
                $threshold = Quantity::fromString((string) $item->low_stock_threshold);
                if ($ats->isLessThanOrEqual($threshold) && $ats->isPositive()) {
                    $isLowStock = true;
                }
            }
        }

        $isInStock = $totalAvailable->isPositive();
        $status = 'out_of_stock';
        if ($isInStock) {
            $status = $isLowStock ? 'low_stock' : 'in_stock';
        } elseif ($isBackorderable) {
            $status = 'backorder';
        }

        return new AvailabilityResultDTO(
            productId: $productId,
            variantId: $variantId,
            availableQuantity: $totalAvailable,
            isInStock: $isInStock,
            isBackorderable: $isBackorderable,
            isLowStock: $isLowStock,
            stockStatus: $status,
            sourceBreakdown: $sourceBreakdown
        );
    }
}
