<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\Models\InventoryTransfer;

interface InventoryTransferServiceInterface
{
    public function dispatch(InventoryTransfer $transfer, ?string $idempotencyKey = null): bool;

    public function receive(InventoryTransfer $transfer, ?string $idempotencyKey = null): bool;

    public function cancel(InventoryTransfer $transfer): bool;
}
