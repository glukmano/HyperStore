<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\Models\InventoryTransfer;

interface InventoryTransferServiceInterface
{
    public function dispatch(InventoryTransfer $transfer, ?string $idempotencyKey = null): bool;

    /**
     * @param  array<int, string>  $receivedQuantities  [item_id => received_quantity_string]
     */
    public function receive(InventoryTransfer $transfer, array $receivedQuantities = [], ?string $idempotencyKey = null): bool;

    public function cancel(InventoryTransfer $transfer): bool;
}
