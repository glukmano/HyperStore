<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\DTOs\ReservationResultDTO;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryReservationAllocation;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\ValueObjects\Quantity;

class InventoryReservationService implements InventoryReservationServiceInterface
{
    public function __construct(
        private readonly InventoryIdempotencyService $idempotencyService
    ) {}

    public function reserve(
        string $reservationKey,
        int $productId,
        ?int $variantId,
        Quantity $requestedQuantity,
        InventoryContext $context,
        int $ttlMinutes = 15,
        ?string $idempotencyKey = null
    ): ReservationResultDTO {
        return $this->idempotencyService->execute(
            $context->tenantId,
            $idempotencyKey,
            'reserve',
            'inventory_reservations',
            $reservationKey,
            function () use ($reservationKey, $productId, $variantId, $requestedQuantity, $context, $ttlMinutes) {
                return DB::transaction(function () use ($reservationKey, $productId, $variantId, $requestedQuantity, $context, $ttlMinutes) {
                    // Check if reservation key already exists
                    $existing = InventoryReservation::query()
                        ->where('tenant_id', $context->tenantId)
                        ->where('reservation_key', $reservationKey)
                        ->first();

                    if ($existing !== null) {
                        return new ReservationResultDTO(
                            isSuccess: false,
                            reservation: null,
                            reservedQuantity: Quantity::zero(),
                            message: "Reservation with key [{$reservationKey}] already exists."
                        );
                    }

                    // 1. Fetch eligible sources
                    $eligibleSourceIds = InventorySource::query()
                        ->where('tenant_id', $context->tenantId)
                        ->where('status', 'active')
                        ->orderByDesc('priority')
                        ->pluck('id')
                        ->all();

                    // 2. Fetch candidate StockItem IDs and sort deterministically (ASC) to prevent deadlocks
                    $stockItemIds = StockItem::query()
                        ->where('tenant_id', $context->tenantId)
                        ->whereIn('inventory_source_id', $eligibleSourceIds)
                        ->where('product_id', $productId)
                        ->where('product_variant_id', $variantId)
                        ->orderBy('id', 'asc')
                        ->pluck('id')
                        ->all();

                    if (empty($stockItemIds)) {
                        return new ReservationResultDTO(
                            isSuccess: false,
                            reservation: null,
                            reservedQuantity: Quantity::zero(),
                            message: "No stock items found for product [{$productId}]."
                        );
                    }

                    // 3. Acquire pessimistic locks in deterministic order
                    /** @var Collection<int, StockItem> $lockedItems */
                    $lockedItems = StockItem::query()
                        ->whereIn('id', $stockItemIds)
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get();

                    $remainingToReserve = $requestedQuantity;
                    $allocationsPlan = [];
                    $backorderAllowed = false;

                    foreach ($lockedItems as $item) {
                        if ($remainingToReserve->isZero()) {
                            break;
                        }

                        $ats = $item->getAvailableToSellQuantity();
                        if ($item->backorder_mode === 'allow' || $item->backorder_mode === 'allow_with_limit') {
                            $backorderAllowed = true;
                        }

                        if ($ats->isPositive()) {
                            $allocQty = $ats->isGreaterThanOrEqual($remainingToReserve) ? $remainingToReserve : $ats;
                            $allocationsPlan[] = [
                                'stock_item' => $item,
                                'quantity' => $allocQty,
                            ];
                            $remainingToReserve = $remainingToReserve->subtract($allocQty);
                        }
                    }

                    // If remaining > 0 and backorder is allowed on the last item, allocate remainder
                    if ($remainingToReserve->isPositive() && $backorderAllowed && count($lockedItems) > 0) {
                        $primaryItem = $lockedItems->first();
                        $allocationsPlan[] = [
                            'stock_item' => $primaryItem,
                            'quantity' => $remainingToReserve,
                        ];
                        $remainingToReserve = Quantity::zero();
                    }

                    if ($remainingToReserve->isPositive()) {
                        return new ReservationResultDTO(
                            isSuccess: false,
                            reservation: null,
                            reservedQuantity: Quantity::zero(),
                            message: "Insufficient available stock to satisfy requested quantity [{$requestedQuantity->toString()}]."
                        );
                    }

                    // 4. Create Parent Reservation
                    $reservation = InventoryReservation::create([
                        'tenant_id' => $context->tenantId,
                        'reservation_key' => $reservationKey,
                        'status' => 'active',
                        'expires_at' => Carbon::now()->addMinutes($ttlMinutes),
                    ]);

                    $recordedAllocations = [];

                    // 5. Create Child Allocations & update stock_items.reserved
                    foreach ($allocationsPlan as $plan) {
                        /** @var StockItem $stockItem */
                        $stockItem = $plan['stock_item'];
                        /** @var Quantity $qty */
                        $qty = $plan['quantity'];

                        InventoryReservationAllocation::create([
                            'inventory_reservation_id' => $reservation->id,
                            'stock_item_id' => $stockItem->id,
                            'inventory_source_id' => $stockItem->inventory_source_id,
                            'product_id' => $productId,
                            'product_variant_id' => $variantId,
                            'quantity' => $qty->toString(),
                        ]);

                        $currentReserved = Quantity::fromString((string) $stockItem->reserved);
                        $stockItem->reserved = $currentReserved->add($qty)->toString();
                        $stockItem->save();

                        $recordedAllocations[] = [
                            'stock_item_id' => $stockItem->id,
                            'source_id' => $stockItem->inventory_source_id,
                            'quantity' => $qty,
                        ];
                    }

                    return new ReservationResultDTO(
                        isSuccess: true,
                        reservation: $reservation,
                        reservedQuantity: $requestedQuantity,
                        message: 'Reservation created successfully.',
                        allocations: $recordedAllocations
                    );
                });
            }
        );
    }

    public function release(string $reservationKey, ?string $idempotencyKey = null): bool
    {
        return DB::transaction(function () use ($reservationKey) {
            /** @var InventoryReservation|null $reservation */
            $reservation = InventoryReservation::query()
                ->where('reservation_key', $reservationKey)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return false;
            }

            foreach ($reservation->allocations as $alloc) {
                /** @var InventoryReservationAllocation $alloc */
                /** @var StockItem $stockItem */
                $stockItem = StockItem::query()->where('id', $alloc->stock_item_id)->lockForUpdate()->first();
                if ($stockItem !== null) {
                    $allocQty = Quantity::fromString((string) $alloc->quantity);
                    $currentReserved = Quantity::fromString((string) $stockItem->reserved);
                    $newReserved = $currentReserved->subtract($allocQty);
                    $stockItem->reserved = $newReserved->isNegative() ? '0.0000' : $newReserved->toString();
                    $stockItem->save();
                }
            }

            $reservation->status = 'released';
            $reservation->released_at = Carbon::now();
            $reservation->save();

            return true;
        });
    }

    public function commit(string $reservationKey, ?string $idempotencyKey = null): bool
    {
        return DB::transaction(function () use ($reservationKey) {
            /** @var InventoryReservation|null $reservation */
            $reservation = InventoryReservation::query()
                ->where('reservation_key', $reservationKey)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return false;
            }

            foreach ($reservation->allocations as $alloc) {
                /** @var InventoryReservationAllocation $alloc */
                /** @var StockItem $stockItem */
                $stockItem = StockItem::query()->where('id', $alloc->stock_item_id)->lockForUpdate()->first();
                if ($stockItem !== null) {
                    $allocQty = Quantity::fromString((string) $alloc->quantity);

                    // Deduct from reserved
                    $currentReserved = Quantity::fromString((string) $stockItem->reserved);
                    $newReserved = $currentReserved->subtract($allocQty);
                    $stockItem->reserved = $newReserved->isNegative() ? '0.0000' : $newReserved->toString();

                    // Deduct from on_hand
                    $currentOnHand = Quantity::fromString((string) $stockItem->on_hand);
                    $newOnHand = $currentOnHand->subtract($allocQty);
                    $stockItem->on_hand = $newOnHand->toString();
                    $stockItem->save();

                    // Write immutable movement
                    InventoryMovement::create([
                        'tenant_id' => $stockItem->tenant_id,
                        'stock_item_id' => $stockItem->id,
                        'inventory_source_id' => $stockItem->inventory_source_id,
                        'product_id' => $stockItem->product_id,
                        'product_variant_id' => $stockItem->product_variant_id,
                        'quantity_delta' => '-'.$allocQty->toString(),
                        'resulting_on_hand' => $stockItem->on_hand,
                        'movement_type' => 'reservation_commit',
                        'reference_type' => 'inventory_reservation',
                        'reference_id' => $reservation->reservation_key,
                        'reason' => 'Committed reservation',
                        'created_at' => now(),
                    ]);
                }
            }

            $reservation->status = 'committed';
            $reservation->committed_at = Carbon::now();
            $reservation->save();

            return true;
        });
    }

    public function expire(InventoryReservation $reservation): bool
    {
        return DB::transaction(function () use ($reservation) {
            if ($reservation->status !== 'active') {
                return false;
            }

            foreach ($reservation->allocations as $alloc) {
                /** @var InventoryReservationAllocation $alloc */
                /** @var StockItem $stockItem */
                $stockItem = StockItem::query()->where('id', $alloc->stock_item_id)->lockForUpdate()->first();
                if ($stockItem !== null) {
                    $allocQty = Quantity::fromString((string) $alloc->quantity);
                    $currentReserved = Quantity::fromString((string) $stockItem->reserved);
                    $newReserved = $currentReserved->subtract($allocQty);
                    $stockItem->reserved = $newReserved->isNegative() ? '0.0000' : $newReserved->toString();
                    $stockItem->save();
                }
            }

            $reservation->status = 'expired';
            $reservation->save();

            return true;
        });
    }
}
