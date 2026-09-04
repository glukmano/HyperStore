<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Catalog\Models\Product;
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\Events\StockTransferReceived;
use Modules\Inventory\Events\StockTransferred;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\InventoryTransferItem;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Support\QuantityScaleGuard;
use Modules\Inventory\Support\WarehouseVendorAuthorizationGuard;
use Modules\Inventory\ValueObjects\Quantity;

class InventoryTransferService implements InventoryTransferServiceInterface
{
    public function __construct(
        private readonly InventoryIdempotencyService $idempotencyService
    ) {}

    /**
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
    ): InventoryTransfer {
        if ($initialStatus !== 'draft' && $initialStatus !== 'requested') {
            throw new InvalidArgumentException('initialStatus must be "draft" or "requested".');
        }

        if (empty($items)) {
            throw new InvalidArgumentException('Transfer must contain at least one item.');
        }

        $payload = [
            'tenant_id' => $tenantId,
            'source' => $sourceInventorySourceId,
            'destination' => $destinationInventorySourceId,
            'transfer_number' => $transferNumber,
            'items' => $items,
            'initial_status' => $initialStatus,
        ];

        /** @var InventoryTransfer $result */
        $result = $this->idempotencyService->execute(
            $tenantId,
            $idempotencyKey,
            'transfer_create',
            'inventory_transfers',
            null,
            function () use ($tenantId, $sourceInventorySourceId, $destinationInventorySourceId, $transferNumber, $items, $initialStatus) {
                return DB::transaction(function () use ($tenantId, $sourceInventorySourceId, $destinationInventorySourceId, $transferNumber, $items, $initialStatus) {
                    if ($sourceInventorySourceId === $destinationInventorySourceId) {
                        throw new InvalidArgumentException('Source and Destination InventorySources must be different.');
                    }

                    // Deterministic lock order (ascending id) prevents deadlock against a concurrent
                    // create() for the reverse-direction pair.
                    $lockIds = [$sourceInventorySourceId, $destinationInventorySourceId];
                    sort($lockIds);

                    /** @var array<int, InventorySource> $lockedSources */
                    $lockedSources = InventorySource::query()
                        ->where('tenant_id', $tenantId)
                        ->whereIn('id', $lockIds)
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id')
                        ->all();

                    $source = $lockedSources[$sourceInventorySourceId] ?? null;
                    $destination = $lockedSources[$destinationInventorySourceId] ?? null;

                    if ($source === null || $destination === null) {
                        throw new InvalidArgumentException('Source and Destination InventorySources must exist and belong to the tenant.');
                    }

                    $this->assertSourceActiveForNewTransfer($source, 'Source');
                    $this->assertSourceActiveForNewTransfer($destination, 'Destination');

                    $seenLines = [];
                    foreach ($items as $itemData) {
                        $productId = (int) $itemData['product_id'];
                        $variantId = isset($itemData['product_variant_id']) ? (int) $itemData['product_variant_id'] : null;
                        $requestedQuantity = (string) $itemData['requested_quantity'];

                        QuantityScaleGuard::assertScale4Representable($requestedQuantity, 'requested_quantity');
                        $qty = Quantity::fromString($requestedQuantity);
                        if (! $qty->isPositive()) {
                            throw new InvalidArgumentException("Transfer item quantity for product [{$productId}] must be greater than zero.");
                        }

                        $lineKey = $productId.':'.($variantId ?? 'null');
                        if (isset($seenLines[$lineKey])) {
                            throw new InvalidArgumentException("Duplicate transfer line for product [{$productId}] variant [{$variantId}].");
                        }
                        $seenLines[$lineKey] = true;

                        /** @var Product|null $product */
                        $product = Product::query()->where('id', $productId)->first();
                        if ($product === null || (int) $product->tenant_id !== $tenantId) {
                            throw new InvalidArgumentException("Product [{$productId}] does not belong to tenant [{$tenantId}].");
                        }
                    }

                    /** @var InventoryTransfer $transfer */
                    $transfer = InventoryTransfer::create([
                        'tenant_id' => $tenantId,
                        'transfer_number' => $transferNumber,
                        'source_inventory_source_id' => $source->id,
                        'destination_inventory_source_id' => $destination->id,
                        'source_warehouse_id' => $source->warehouse_id,
                        'destination_warehouse_id' => $destination->warehouse_id,
                        'status' => $initialStatus,
                    ]);

                    foreach ($items as $itemData) {
                        InventoryTransferItem::create([
                            'tenant_id' => $tenantId,
                            'inventory_transfer_id' => $transfer->id,
                            'product_id' => (int) $itemData['product_id'],
                            'product_variant_id' => isset($itemData['product_variant_id']) ? (int) $itemData['product_variant_id'] : null,
                            'requested_quantity' => (string) $itemData['requested_quantity'],
                        ]);
                    }

                    return $transfer;
                });
            },
            $payload
        );

        return $result;
    }

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
                    $destSourceId = $lockedTransfer->destination_inventory_source_id;

                    /** @var InventorySource $lockedSource */
                    $lockedSource = InventorySource::query()
                        ->where('tenant_id', $lockedTransfer->tenant_id)
                        ->where('id', $sourceSourceId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $this->assertSourceActiveForNewTransfer($lockedSource, 'Source');

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

                        // Reservation safety (ADR-0125): dispatch must never take stock actively
                        // reserved for Checkout/Order — validate against available-to-sell, not raw on_hand.
                        $available = $sourceStock->getAvailableToSellQuantity();
                        if ($available->isLessThan($reqQty)) {
                            throw new InvalidArgumentException('Source inventory source does not have enough available (unreserved) stock to dispatch transfer.');
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
                            'reason' => "Dispatched transfer to destination source #{$destSourceId}",
                            'created_at' => now(),
                        ]);

                        // Conservation (ADR-0125): the dispatched quantity becomes destination-bound
                        // in-transit stock (`incoming`), not yet sellable, until received.
                        /** @var StockItem $destStock */
                        $destStock = StockItem::firstOrCreate([
                            'tenant_id' => $lockedTransfer->tenant_id,
                            'inventory_source_id' => $destSourceId,
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                        ], [
                            'on_hand' => '0.0000',
                            'reserved' => '0.0000',
                            'incoming' => '0.0000',
                        ]);

                        $lockedDestStock = StockItem::query()->where('id', $destStock->id)->lockForUpdate()->firstOrFail();
                        $currentIncoming = Quantity::fromString((string) $lockedDestStock->incoming);
                        $lockedDestStock->incoming = $currentIncoming->add($reqQty)->toString();
                        $lockedDestStock->save();

                        InventoryMovement::create([
                            'tenant_id' => $lockedTransfer->tenant_id,
                            'stock_item_id' => $lockedDestStock->id,
                            'inventory_source_id' => $destSourceId,
                            'product_id' => $item->product_id,
                            'product_variant_id' => $item->product_variant_id,
                            'quantity_delta' => $reqQty->toString(),
                            'resulting_on_hand' => $lockedDestStock->on_hand,
                            'movement_type' => 'transfer_pending_in',
                            'reference_type' => 'inventory_transfer',
                            'reference_id' => $lockedTransfer->transfer_number,
                            'reason' => "In-transit from source #{$sourceSourceId} (not yet received)",
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
     * @param  array<int, string|array{good?: string, damaged?: string, quarantine?: string}>  $receivedQuantities
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

                        [$goodQty, $damagedQty, $quarantineQty] = $this->resolveDisposition(
                            $receivedQuantities[$item->id] ?? null,
                            $remainingQty
                        );

                        $incQty = $goodQty->add($damagedQty)->add($quarantineQty);

                        if ($goodQty->isNegative() || $damagedQty->isNegative() || $quarantineQty->isNegative()) {
                            throw new InvalidArgumentException('Received quantity increments cannot be negative.');
                        }

                        if ($incQty->isZero()) {
                            if (! $currRecQty->equals($dispQty)) {
                                $allFullyReceived = false;
                            }

                            continue;
                        }

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
                            'incoming' => '0.0000',
                        ]);

                        $lockedDestStock = StockItem::query()->where('id', $destStock->id)->lockForUpdate()->firstOrFail();

                        $currentIncoming = Quantity::fromString((string) $lockedDestStock->incoming);
                        $newIncoming = $currentIncoming->subtract($incQty);
                        $lockedDestStock->incoming = $newIncoming->isNegative() ? '0.0000' : $newIncoming->toString();

                        if ($goodQty->isPositive()) {
                            $currentOnHand = Quantity::fromString((string) $lockedDestStock->on_hand);
                            $lockedDestStock->on_hand = $currentOnHand->add($goodQty)->toString();
                        }
                        if ($damagedQty->isPositive()) {
                            $currentDamaged = Quantity::fromString((string) $lockedDestStock->damaged);
                            $lockedDestStock->damaged = $currentDamaged->add($damagedQty)->toString();
                        }
                        if ($quarantineQty->isPositive()) {
                            $currentQuarantined = Quantity::fromString((string) $lockedDestStock->quarantined);
                            $lockedDestStock->quarantined = $currentQuarantined->add($quarantineQty)->toString();
                        }

                        $lockedDestStock->save();

                        if ($goodQty->isPositive()) {
                            InventoryMovement::create([
                                'tenant_id' => $lockedTransfer->tenant_id,
                                'stock_item_id' => $lockedDestStock->id,
                                'inventory_source_id' => $destSourceId,
                                'product_id' => $item->product_id,
                                'product_variant_id' => $item->product_variant_id,
                                'quantity_delta' => $goodQty->toString(),
                                'resulting_on_hand' => $lockedDestStock->on_hand,
                                'movement_type' => 'transfer_in',
                                'reference_type' => 'inventory_transfer',
                                'reference_id' => $lockedTransfer->transfer_number,
                                'reason' => "Received {$goodQty->toString()} good units for transfer from source #{$lockedTransfer->source_inventory_source_id}",
                                'created_at' => now(),
                            ]);
                        }
                        if ($damagedQty->isPositive()) {
                            InventoryMovement::create([
                                'tenant_id' => $lockedTransfer->tenant_id,
                                'stock_item_id' => $lockedDestStock->id,
                                'inventory_source_id' => $destSourceId,
                                'product_id' => $item->product_id,
                                'product_variant_id' => $item->product_variant_id,
                                'quantity_delta' => '0.0000',
                                'resulting_on_hand' => $lockedDestStock->on_hand,
                                'movement_type' => 'damaged',
                                'reference_type' => 'inventory_transfer',
                                'reference_id' => $lockedTransfer->transfer_number,
                                'reason' => "Received {$damagedQty->toString()} damaged units for transfer from source #{$lockedTransfer->source_inventory_source_id}",
                                'created_at' => now(),
                            ]);
                        }
                        if ($quarantineQty->isPositive()) {
                            InventoryMovement::create([
                                'tenant_id' => $lockedTransfer->tenant_id,
                                'stock_item_id' => $lockedDestStock->id,
                                'inventory_source_id' => $destSourceId,
                                'product_id' => $item->product_id,
                                'product_variant_id' => $item->product_variant_id,
                                'quantity_delta' => '0.0000',
                                'resulting_on_hand' => $lockedDestStock->on_hand,
                                'movement_type' => 'quarantine_in',
                                'reference_type' => 'inventory_transfer',
                                'reference_id' => $lockedTransfer->transfer_number,
                                'reason' => "Received {$quarantineQty->toString()} quarantined units for transfer from source #{$lockedTransfer->source_inventory_source_id}",
                                'created_at' => now(),
                            ]);
                        }

                        $newTotalRec = $currRecQty->add($incQty);
                        $item->received_quantity = $newTotalRec->toString();
                        $item->save();

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

    /**
     * @param  string|array{good?: string, damaged?: string, quarantine?: string}|null  $disposition
     * @return array{0: Quantity, 1: Quantity, 2: Quantity} [good, damaged, quarantine]
     */
    private function resolveDisposition(string|array|null $disposition, Quantity $remainingQty): array
    {
        if ($disposition === null) {
            return [$remainingQty, Quantity::zero(), Quantity::zero()];
        }

        if (is_string($disposition)) {
            return [Quantity::fromString($disposition), Quantity::zero(), Quantity::zero()];
        }

        return [
            Quantity::fromString((string) ($disposition['good'] ?? '0')),
            Quantity::fromString((string) ($disposition['damaged'] ?? '0')),
            Quantity::fromString((string) ($disposition['quarantine'] ?? '0')),
        ];
    }

    /**
     * Deactivation semantics (ADR-0125): a NEW transfer (create) or a dispatch requires the
     * InventorySource — and its parent Warehouse, if any — to be active. Receive intentionally
     * does not call this (historical-completion/recovery path).
     */
    private function assertSourceActiveForNewTransfer(InventorySource $source, string $label): void
    {
        if ($source->status !== 'active') {
            throw new InvalidArgumentException("{$label} InventorySource [{$source->id}] is not active.");
        }

        if ($source->warehouse_id !== null) {
            /** @var Warehouse|null $warehouse */
            $warehouse = Warehouse::query()->where('id', $source->warehouse_id)->first();
            if ($warehouse !== null && $warehouse->status !== 'active') {
                throw new InvalidArgumentException("{$label} InventorySource [{$source->id}] belongs to inactive Warehouse [{$warehouse->id}].");
            }
            WarehouseVendorAuthorizationGuard::assertWarehouseOperable($warehouse);
        }
    }
}
