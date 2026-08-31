<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventorySource;
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
                    $lockedTransfer = InventoryTransfer::query()->where('id', $transfer->id)->lockForUpdate()->firstOrFail();
                    if ($lockedTransfer->status !== 'draft' && $lockedTransfer->status !== 'requested') {
                        throw new InvalidArgumentException('Transfer must be in draft or requested status to dispatch.');
                    }

                    // Source warehouse inventory source
                    $sourceInvSource = InventorySource::query()
                        ->where('tenant_id', $lockedTransfer->tenant_id)
                        ->where('warehouse_id', $lockedTransfer->source_warehouse_id)
                        ->firstOrFail();

                    foreach ($lockedTransfer->items as $item) {
                        /** @var InventoryTransferItem $item */
                        /** @var StockItem $sourceStock */
                        $sourceStock = StockItem::query()
                            ->where('tenant_id', $lockedTransfer->tenant_id)
                            ->where('inventory_source_id', $sourceInvSource->id)
                            ->where('product_id', $item->product_id)
                            ->where('product_variant_id', $item->product_variant_id)
                            ->lockForUpdate()
                            ->firstOrFail();

                        $reqQty = Quantity::fromString((string) $item->requested_quantity);
                        $currentOnHand = Quantity::fromString((string) $sourceStock->on_hand);

                        if ($currentOnHand->isLessThan($reqQty)) {
                            throw new InvalidArgumentException('Source warehouse does not have enough on-hand stock to dispatch transfer.');
                        }

                        // Deduct from source on_hand
                        $sourceStock->on_hand = $currentOnHand->subtract($reqQty)->toString();
                        $sourceStock->save();

                        $item->dispatched_quantity = $reqQty->toString();
                        $item->save();

                        // Write transfer_out movement
                        InventoryMovement::create([
                            'tenant_id' => $lockedTransfer->tenant_id,
                            'stock_item_id' => $sourceStock->id,
                            'inventory_source_id' => $sourceInvSource->id,
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                            'quantity_delta' => '-'.$reqQty->toString(),
                            'resulting_on_hand' => $sourceStock->on_hand,
                            'movement_type' => 'transfer_out',
                            'reference_type' => 'inventory_transfer',
                            'reference_id' => $lockedTransfer->transfer_number,
                            'reason' => "Dispatched transfer to destination warehouse #{$lockedTransfer->destination_warehouse_id}",
                            'created_at' => now(),
                        ]);
                    }

                    $lockedTransfer->status = 'in_transit';
                    $lockedTransfer->dispatched_at = Carbon::now();
                    $lockedTransfer->save();

                    return true;
                });
            }
        );
    }

    public function receive(InventoryTransfer $transfer, ?string $idempotencyKey = null): bool
    {
        return $this->idempotencyService->execute(
            $transfer->tenant_id,
            $idempotencyKey,
            'receive_transfer',
            'inventory_transfers',
            (string) $transfer->id,
            function () use ($transfer) {
                return DB::transaction(function () use ($transfer) {
                    /** @var InventoryTransfer $lockedTransfer */
                    $lockedTransfer = InventoryTransfer::query()->where('id', $transfer->id)->lockForUpdate()->firstOrFail();
                    if ($lockedTransfer->status !== 'in_transit') {
                        throw new InvalidArgumentException('Transfer must be in_transit to receive.');
                    }

                    $destInvSource = InventorySource::query()
                        ->where('tenant_id', $lockedTransfer->tenant_id)
                        ->where('warehouse_id', $lockedTransfer->destination_warehouse_id)
                        ->firstOrFail();

                    foreach ($lockedTransfer->items as $item) {
                        /** @var InventoryTransferItem $item */
                        $dispQty = Quantity::fromString((string) $item->dispatched_quantity);

                        /** @var StockItem $destStock */
                        $destStock = StockItem::firstOrCreate([
                            'tenant_id' => $lockedTransfer->tenant_id,
                            'inventory_source_id' => $destInvSource->id,
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                        ], [
                            'on_hand' => '0.0000',
                            'reserved' => '0.0000',
                        ]);

                        $lockedDestStock = StockItem::query()->where('id', $destStock->id)->lockForUpdate()->firstOrFail();
                        $currentOnHand = Quantity::fromString((string) $lockedDestStock->on_hand);

                        $lockedDestStock->on_hand = $currentOnHand->add($dispQty)->toString();
                        $lockedDestStock->save();

                        $item->received_quantity = $dispQty->toString();
                        $item->save();

                        InventoryMovement::create([
                            'tenant_id' => $lockedTransfer->tenant_id,
                            'stock_item_id' => $lockedDestStock->id,
                            'inventory_source_id' => $destInvSource->id,
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                            'quantity_delta' => $dispQty->toString(),
                            'resulting_on_hand' => $lockedDestStock->on_hand,
                            'movement_type' => 'transfer_in',
                            'reference_type' => 'inventory_transfer',
                            'reference_id' => $lockedTransfer->transfer_number,
                            'reason' => "Received transfer from source warehouse #{$lockedTransfer->source_warehouse_id}",
                            'created_at' => now(),
                        ]);
                    }

                    $lockedTransfer->status = 'received';
                    $lockedTransfer->received_at = Carbon::now();
                    $lockedTransfer->save();

                    return true;
                });
            }
        );
    }

    public function cancel(InventoryTransfer $transfer): bool
    {
        return DB::transaction(function () use ($transfer) {
            /** @var InventoryTransfer $lockedTransfer */
            $lockedTransfer = InventoryTransfer::query()->where('id', $transfer->id)->lockForUpdate()->firstOrFail();
            if ($lockedTransfer->status === 'in_transit' || $lockedTransfer->status === 'received') {
                throw new InvalidArgumentException('In-transit or received transfers cannot be cancelled.');
            }

            $lockedTransfer->status = 'cancelled';
            $lockedTransfer->cancelled_at = Carbon::now();
            $lockedTransfer->save();

            return true;
        });
    }
}
