<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\ValueObjects\Quantity;

interface InventoryAdjustmentServiceInterface
{
    public function adjust(
        StockItem $stockItem,
        Quantity $delta,
        string $movementType,
        ?string $reason = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $idempotencyKey = null
    ): InventoryMovement;

    public function receive(
        StockItem $stockItem,
        Quantity $quantity,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $idempotencyKey = null
    ): InventoryMovement;

    public function quarantine(
        StockItem $stockItem,
        Quantity $quantity,
        ?string $reason = null,
        ?string $idempotencyKey = null
    ): InventoryMovement;

    public function releaseQuarantine(
        StockItem $stockItem,
        Quantity $quantity,
        ?string $reason = null,
        ?string $idempotencyKey = null
    ): InventoryMovement;

    public function markDamaged(
        StockItem $stockItem,
        Quantity $quantity,
        ?string $reason = null,
        ?string $idempotencyKey = null
    ): InventoryMovement;
}
