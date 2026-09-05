<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface;
use Modules\Inventory\Events\InventoryAdjusted;
use Modules\Inventory\Events\InventoryReceived;
use Modules\Inventory\Events\LowStockDetected;
use Modules\Inventory\Events\OutOfStockDetected;
use Modules\Inventory\Events\StockReplenished;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Support\WarehouseVendorAuthorizationGuard;
use Modules\Inventory\ValueObjects\Quantity;

class InventoryAdjustmentService implements InventoryAdjustmentServiceInterface
{
    public function __construct(
        private readonly InventoryIdempotencyService $idempotencyService
    ) {}

    public function adjust(
        StockItem $stockItem,
        Quantity $delta,
        string $movementType,
        ?string $reason = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $idempotencyKey = null
    ): InventoryMovement {
        if ($delta->isZero()) {
            throw new InvalidArgumentException('Adjustment delta cannot be zero.');
        }

        if (! in_array($movementType, InventoryMovement::VALID_TYPES, true)) {
            throw new InvalidArgumentException("Invalid movement type [{$movementType}].");
        }

        return $this->idempotencyService->execute(
            $stockItem->tenant_id,
            $idempotencyKey,
            'adjust',
            'stock_items',
            (string) $stockItem->id,
            function () use ($stockItem, $delta, $movementType, $reason, $referenceType, $referenceId) {
                return DB::transaction(function () use ($stockItem, $delta, $movementType, $reason, $referenceType, $referenceId) {
                    /** @var StockItem $locked */
                    $locked = StockItem::query()->where('id', $stockItem->id)->lockForUpdate()->firstOrFail();

                    /** @var InventorySource|null $invSource */
                    $invSource = InventorySource::query()->where('id', $locked->inventory_source_id)->first();
                    if ($invSource !== null && $invSource->warehouse_id !== null) {
                        /** @var Warehouse|null $warehouse */
                        $warehouse = Warehouse::query()->where('id', $invSource->warehouse_id)->first();
                        WarehouseVendorAuthorizationGuard::assertWarehouseOperable($warehouse);
                    }

                    $previousAts = $locked->getAvailableToSellQuantity();

                    $currentOnHand = Quantity::fromString((string) $locked->on_hand);
                    $newOnHand = $currentOnHand->add($delta);

                    $locked->on_hand = $newOnHand->toString();
                    $locked->save();

                    $movement = InventoryMovement::create([
                        'tenant_id' => $locked->tenant_id,
                        'stock_item_id' => $locked->id,
                        'inventory_source_id' => $locked->inventory_source_id,
                        'product_id' => $locked->product_id,
                        'product_variant_id' => $locked->product_variant_id,
                        'quantity_delta' => $delta->toString(),
                        'resulting_on_hand' => $locked->on_hand,
                        'movement_type' => $movementType,
                        'reference_type' => $referenceType,
                        'reference_id' => $referenceId,
                        'reason' => $reason ?? 'Inventory adjustment',
                        'created_at' => now(),
                    ]);

                    $newAts = $locked->getAvailableToSellQuantity();
                    if ($newAts->isZero()) {
                        OutOfStockDetected::dispatch($locked);
                    } elseif ($locked->low_stock_threshold !== null) {
                        $thresh = Quantity::fromString((string) $locked->low_stock_threshold);
                        if ($newAts->isLessThanOrEqual($thresh)) {
                            LowStockDetected::dispatch($locked, $newAts);
                        }
                    }

                    if (! $previousAts->isPositive() && $newAts->isPositive()) {
                        StockReplenished::dispatch(
                            $locked->tenant_id,
                            $locked->inventory_source_id,
                            $locked->product_id,
                            $locked->product_variant_id,
                            $previousAts->toString(),
                            $newAts->toString(),
                        );
                    }

                    InventoryAdjusted::dispatch($locked, $movement);

                    return $movement;
                });
            }
        );
    }

    public function receive(
        StockItem $stockItem,
        Quantity $quantity,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $idempotencyKey = null
    ): InventoryMovement {
        if ($quantity->isNegative() || $quantity->isZero()) {
            throw new InvalidArgumentException('Received quantity must be positive.');
        }

        $movement = $this->adjust(
            stockItem: $stockItem,
            delta: $quantity,
            movementType: 'receive',
            reason: 'Physical stock receiving',
            referenceType: $referenceType,
            referenceId: $referenceId,
            idempotencyKey: $idempotencyKey
        );

        InventoryReceived::dispatch($stockItem, $movement);

        return $movement;
    }

    public function quarantine(
        StockItem $stockItem,
        Quantity $quantity,
        ?string $reason = null,
        ?string $idempotencyKey = null
    ): InventoryMovement {
        if ($quantity->isNegative() || $quantity->isZero()) {
            throw new InvalidArgumentException('Quarantine quantity must be positive.');
        }

        return DB::transaction(function () use ($stockItem, $quantity, $reason) {
            /** @var StockItem $locked */
            $locked = StockItem::query()->where('id', $stockItem->id)->lockForUpdate()->firstOrFail();

            $currQuar = Quantity::fromString((string) $locked->quarantined);
            $locked->quarantined = $currQuar->add($quantity)->toString();
            $locked->save();

            return InventoryMovement::create([
                'tenant_id' => $locked->tenant_id,
                'stock_item_id' => $locked->id,
                'inventory_source_id' => $locked->inventory_source_id,
                'product_id' => $locked->product_id,
                'product_variant_id' => $locked->product_variant_id,
                'quantity_delta' => '0.0000',
                'resulting_on_hand' => $locked->on_hand,
                'movement_type' => 'quarantine_in',
                'reason' => $reason ?? 'Stock moved to quarantine',
                'created_at' => now(),
            ]);
        });
    }

    public function releaseQuarantine(
        StockItem $stockItem,
        Quantity $quantity,
        ?string $reason = null,
        ?string $idempotencyKey = null
    ): InventoryMovement {
        if ($quantity->isNegative() || $quantity->isZero()) {
            throw new InvalidArgumentException('Quarantine release quantity must be positive.');
        }

        return DB::transaction(function () use ($stockItem, $quantity, $reason) {
            /** @var StockItem $locked */
            $locked = StockItem::query()->where('id', $stockItem->id)->lockForUpdate()->firstOrFail();

            $previousAts = $locked->getAvailableToSellQuantity();

            $currQuar = Quantity::fromString((string) $locked->quarantined);
            $newQuar = $currQuar->subtract($quantity);
            $locked->quarantined = $newQuar->isNegative() ? '0.0000' : $newQuar->toString();
            $locked->save();

            $movement = InventoryMovement::create([
                'tenant_id' => $locked->tenant_id,
                'stock_item_id' => $locked->id,
                'inventory_source_id' => $locked->inventory_source_id,
                'product_id' => $locked->product_id,
                'product_variant_id' => $locked->product_variant_id,
                'quantity_delta' => '0.0000',
                'resulting_on_hand' => $locked->on_hand,
                'movement_type' => 'quarantine_out',
                'reason' => $reason ?? 'Stock released from quarantine',
                'created_at' => now(),
            ]);

            $newAts = $locked->getAvailableToSellQuantity();
            if (! $previousAts->isPositive() && $newAts->isPositive()) {
                StockReplenished::dispatch(
                    $locked->tenant_id,
                    $locked->inventory_source_id,
                    $locked->product_id,
                    $locked->product_variant_id,
                    $previousAts->toString(),
                    $newAts->toString(),
                );
            }

            return $movement;
        });
    }

    public function markDamaged(
        StockItem $stockItem,
        Quantity $quantity,
        ?string $reason = null,
        ?string $idempotencyKey = null
    ): InventoryMovement {
        if ($quantity->isNegative() || $quantity->isZero()) {
            throw new InvalidArgumentException('Damaged quantity must be positive.');
        }

        return DB::transaction(function () use ($stockItem, $quantity, $reason) {
            /** @var StockItem $locked */
            $locked = StockItem::query()->where('id', $stockItem->id)->lockForUpdate()->firstOrFail();

            $currDam = Quantity::fromString((string) $locked->damaged);
            $locked->damaged = $currDam->add($quantity)->toString();
            $locked->save();

            return InventoryMovement::create([
                'tenant_id' => $locked->tenant_id,
                'stock_item_id' => $locked->id,
                'inventory_source_id' => $locked->inventory_source_id,
                'product_id' => $locked->product_id,
                'product_variant_id' => $locked->product_variant_id,
                'quantity_delta' => '0.0000',
                'resulting_on_hand' => $locked->on_hand,
                'movement_type' => 'damaged',
                'reason' => $reason ?? 'Stock marked as damaged',
                'created_at' => now(),
            ]);
        });
    }

    public function receiveByIdentity(
        int $tenantId,
        int $inventorySourceId,
        int $productId,
        ?int $productVariantId,
        Quantity $quantity,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $idempotencyKey = null
    ): InventoryMovement {
        $stockItem = $this->resolveOrCreateStockItem($tenantId, $inventorySourceId, $productId, $productVariantId);

        return $this->receive($stockItem, $quantity, $referenceType, $referenceId, $idempotencyKey);
    }

    public function quarantineByIdentity(
        int $tenantId,
        int $inventorySourceId,
        int $productId,
        ?int $productVariantId,
        Quantity $quantity,
        ?string $reason = null,
        ?string $idempotencyKey = null
    ): InventoryMovement {
        $stockItem = $this->resolveOrCreateStockItem($tenantId, $inventorySourceId, $productId, $productVariantId);

        return $this->quarantine($stockItem, $quantity, $reason, $idempotencyKey);
    }

    private function resolveOrCreateStockItem(int $tenantId, int $inventorySourceId, int $productId, ?int $productVariantId): StockItem
    {
        return StockItem::firstOrCreate([
            'tenant_id' => $tenantId,
            'inventory_source_id' => $inventorySourceId,
            'product_id' => $productId,
            'product_variant_id' => $productVariantId,
        ], [
            'on_hand' => '0.0000',
            'reserved' => '0.0000',
        ]);
    }
}
