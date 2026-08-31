<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\Events\StockTransferReceived;
use Modules\Inventory\Events\StockTransferred;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\InventoryTransferItem;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\ValueObjects\Quantity;

class InventoryTransferService implements InventoryTransferServiceInterface
{
    public function __construct(
        private readonly InventoryIdempotencyService $idempotencyService
    ) {}

    public function dispatch(InventoryTransfer $transfer, ?string $idempotencyKey = null): bool
    {
        return $this->idempotencyService->execute(
            $transfer->tenant_id,
            $idempotencyKey,
            'dispatch_transfer',
            'inventory_transfers',
            (string) $transfer->id,
            function () use ($transfer) {
                return DB::transaction(function () use ($transfer) {
                    /** @var InventoryTransfer $lockedTransfer */
                    $lockedTransfer = InventoryTransfer::query()
                        ->where('tenant_id', $transfer->tenant_id)
                        ->where('id', $transfer->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($lockedTransfer->status !== 'draft' && $lockedTransfer->status !== 'requested') {
                        throw new InvalidArgumentException('Transfer must be in draft or requested status to dispatch.');
                    }

                    $sourceSourceId = $lockedTransfer->source_inventory_source_id;
                    $items = $lockedTransfer->items()->orderBy('product_id')->get();

                    foreach ($items as $item) {
                        /** @var InventoryTransferItem $item */
                        /** @var StockItem $sourceStock */
                        $sourceStock = StockItem::query()
                            ->where('tenant_id', $lockedTransfer->tenant_id)
                            ->where('inventory_source_id', $sourceSourceId)
                            ->where('product_id', $item->product_id)
                            ->where('product_variant_id', $item->product_variant_id)
                            ->lockForUpdate()
                            ->firstOrFail();

                        $reqQty = Quantity::fromString((string) $item->requested_quantity);
                        $currentOnHand = Quantity::fromString((string) $sourceStock->on_hand);

                        if ($currentOnHand->isLessThan($reqQty)) {
                            throw new InvalidArgumentException('Source inventory source does not have enough on-hand stock to dispatch transfer.');
                        }

                        $sourceStock->on_hand = $currentOnHand->subtract($reqQty)->toString();
                        $sourceStock->save();

                        $item->dispatched_quantity = $reqQty->toString();
                        $item->received_quantity = '0.0000';
                        $item->save();

                        InventoryMovement::create([
                            'tenant_id' => $lockedTransfer->tenant_id,
                            'stock_item_id' => $sourceStock->id,
                            'inventory_source_id' => $sourceSourceId,
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                            'quantity_delta' => '-'.$reqQty->toString(),
                            'resulting_on_hand' => $sourceStock->on_hand,
                            'movement_type' => 'transfer_out',
                            'reference_type' => 'inventory_transfer',
                            'reference_id' => $lockedTransfer->transfer_number,
                            'reason' => "Dispatched transfer to destination source #{$lockedTransfer->destination_inventory_source_id}",
                            'created_at' => now(),
                        ]);
                    }

                    $lockedTransfer->status = 'in_transit';
                    $lockedTransfer->dispatched_at = Carbon::now();
                    $lockedTransfer->save();

                    StockTransferred::dispatch($lockedTransfer);

                    return true;
                });
            }
        );
    }

    /**
     * @param  array<int, string>  $receivedQuantities  Incremental quantities to receive [item_id => incremental_quantity_string]
     */
    public function receive(InventoryTransfer $transfer, array $receivedQuantities = [], ?string $idempotencyKey = null): bool
    {
        return $this->idempotencyService->execute(
            $transfer->tenant_id,
            $idempotencyKey,
            'receive_transfer',
            'inventory_transfers',
            (string) $transfer->id.'_'.uniqid(),
            function () use ($transfer, $receivedQuantities) {
                return DB::transaction(function () use ($transfer, $receivedQuantities) {
                    /** @var InventoryTransfer $lockedTransfer */
                    $lockedTransfer = InventoryTransfer::query()
                        ->where('tenant_id', $transfer->tenant_id)
                        ->where('id', $transfer->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($lockedTransfer->status === 'received') {
                        throw new InvalidArgumentException('Transfer is already fully received.');
                    }

                    if ($lockedTransfer->status !== 'in_transit' && $lockedTransfer->status !== 'partially_received') {
                        throw new InvalidArgumentException('Transfer must be in_transit or partially_received to receive items.');
                    }

                    $destSourceId = $lockedTransfer->destination_inventory_source_id;
                    $items = $lockedTransfer->items()->orderBy('product_id')->get();
                    $allFullyReceived = true;
                    $anyReceived = false;

                    foreach ($items as $item) {
                        /** @var InventoryTransferItem $item */
                        $dispQty = Quantity::fromString((string) $item->dispatched_quantity);
                        $currRecQty = Quantity::fromString((string) ($item->received_quantity ?? '0.0000'));
                        $remainingQty = $dispQty->subtract($currRecQty);

                        // Incremental quantity to receive in this batch
                        $incQty = isset($receivedQuantities[$item->id])
                            ? Quantity::fromString($receivedQuantities[$item->id])
                            : $remainingQty;

                        if ($incQty->isNegative()) {
                            throw new InvalidArgumentException('Received quantity increment cannot be negative.');
                        }

                        if ($incQty->isZero()) {
                            if (! $currRecQty->equals($dispQty)) {
                                $allFullyReceived = false;
                            }

                            continue;
                        }

                        // Validate against remaining dispatched quantity
                        if ($incQty->isGreaterThan($remainingQty)) {
                            throw new InvalidArgumentException("Incremental received quantity [{$incQty->toString()}] exceeds remaining dispatched quantity [{$remainingQty->toString()}].");
                        }

                        $anyReceived = true;

                        /** @var StockItem $destStock */
                        $destStock = StockItem::firstOrCreate([
                            'tenant_id' => $lockedTransfer->tenant_id,
                            'inventory_source_id' => $destSourceId,
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                        ], [
                            'on_hand' => '0.0000',
                            'reserved' => '0.0000',
                        ]);

                        $lockedDestStock = StockItem::query()->where('id', $destStock->id)->lockForUpdate()->firstOrFail();
                        $currentOnHand = Quantity::fromString((string) $lockedDestStock->on_hand);

                        $lockedDestStock->on_hand = $currentOnHand->add($incQty)->toString();
                        $lockedDestStock->save();

                        $newTotalRec = $currRecQty->add($incQty);
                        $item->received_quantity = $newTotalRec->toString();
                        $item->save();

                        InventoryMovement::create([
                            'tenant_id' => $lockedTransfer->tenant_id,
                            'stock_item_id' => $lockedDestStock->id,
                            'inventory_source_id' => $destSourceId,
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                            'quantity_delta' => $incQty->toString(),
                            'resulting_on_hand' => $lockedDestStock->on_hand,
                            'movement_type' => 'transfer_in',
                            'reference_type' => 'inventory_transfer',
                            'reference_id' => $lockedTransfer->transfer_number,
                            'reason' => "Received {$incQty->toString()} units for transfer from source #{$lockedTransfer->source_inventory_source_id}",
                            'created_at' => now(),
                        ]);

                        if (! $newTotalRec->equals($dispQty)) {
                            $allFullyReceived = false;
                        }
                    }

                    if (! $anyReceived) {
                        throw new InvalidArgumentException('No stock was received in this operation.');
                    }

                    if ($allFullyReceived) {
                        $lockedTransfer->status = 'received';
                        $lockedTransfer->received_at = Carbon::now();
                    } else {
                        $lockedTransfer->status = 'partially_received';
                    }
                    $lockedTransfer->save();

                    StockTransferReceived::dispatch($lockedTransfer);

                    return true;
                });
            }
        );
    }

    public function cancel(InventoryTransfer $transfer): bool
    {
        return DB::transaction(function () use ($transfer) {
            /** @var InventoryTransfer $lockedTransfer */
            $lockedTransfer = InventoryTransfer::query()
                ->where('tenant_id', $transfer->tenant_id)
                ->where('id', $transfer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTransfer->status === 'in_transit' || $lockedTransfer->status === 'partially_received' || $lockedTransfer->status === 'received') {
                throw new InvalidArgumentException('In-transit, partially received, or received transfers cannot be cancelled.');
            }

            $lockedTransfer->status = 'cancelled';
            $lockedTransfer->cancelled_at = Carbon::now();
            $lockedTransfer->save();

            return true;
        });
    }
}
