<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\Models\InventoryTransfer;

interface InventoryTransferServiceInterface
{
    /**
     * Formal, idempotency-wrapped, transactional creation of an InventoryTransfer header
     * and all its line items. ADR-0125.
     *
     * @param  list<array{product_id: int, product_variant_id?: int|null, requested_quantity: string}>  $items
     */
    public function create(
        int $tenantId,
        int $sourceInventorySourceId,
        int $destinationInventorySourceId,
        string $transferNumber,
        array $items,
        string $initialStatus = 'draft',
        ?string $idempotencyKey = null
    ): InventoryTransfer;

    public function dispatch(InventoryTransfer $transfer, ?string $idempotencyKey = null): bool;

    /**
     * @param  array<int, string|array{good?: string, damaged?: string, quarantine?: string}>  $receivedQuantities
     *                                                                                                              [item_id => incremental_quantity_string] (treated as "good") OR
     *                                                                                                              [item_id => ['good' => ..., 'damaged' => ..., 'quarantine' => ...]] for disposition breakdown.
     *                                                                                                              Omitted item_id receives the full remaining dispatched quantity as "good".
     */
    public function receive(InventoryTransfer $transfer, array $receivedQuantities = [], ?string $idempotencyKey = null): bool;

    public function cancel(InventoryTransfer $transfer): bool;
}
