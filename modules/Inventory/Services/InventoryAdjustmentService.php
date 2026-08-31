<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\StockItem;
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

                    $currentOnHand = Quantity::fromString((string) $locked->on_hand);
                    $newOnHand = $currentOnHand->add($delta);

                    $locked->on_hand = $newOnHand->toString();
                    $locked->save();

                    return InventoryMovement::create([
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

        return $this->adjust(
            stockItem: $stockItem,
            delta: $quantity,
            movementType: 'receive',
            reason: 'Physical stock receiving',
            referenceType: $referenceType,
            referenceId: $referenceId,
            idempotencyKey: $idempotencyKey
        );
    }
}
