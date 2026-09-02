<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\DTOs\ReservationAdoptionResultDTO;
use Modules\Inventory\DTOs\ReservationResultDTO;
use Modules\Inventory\Enums\ReservationOwnerType;
use Modules\Inventory\Events\InventoryCommitted;
use Modules\Inventory\Events\InventoryReservationAdopted;
use Modules\Inventory\Events\InventoryReservationExpired;
use Modules\Inventory\Events\InventoryReservationReleased;
use Modules\Inventory\Events\InventoryReserved;
use Modules\Inventory\Events\LowStockDetected;
use Modules\Inventory\Events\OutOfStockDetected;
use Modules\Inventory\Exceptions\ReservationAdoptionException;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryReservationAllocation;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\ValueObjects\Quantity;

class InventoryReservationService implements InventoryReservationServiceInterface
{
    public function __construct(
        private readonly InventoryIdempotencyService $idempotencyService,
        private readonly InventorySourceEligibilityService $eligibilityService
    ) {}

    public function reserve(
        int $tenantId,
        string $reservationKey,
        int $productId,
        ?int $variantId,
        Quantity $requestedQuantity,
        InventoryContext $context,
        int $ttlMinutes = 15,
        ?string $idempotencyKey = null
    ): ReservationResultDTO {
        return $this->idempotencyService->execute(
            $tenantId,
            $idempotencyKey,
            'reserve',
            'inventory_reservations',
            $reservationKey,
            function () use ($tenantId, $reservationKey, $productId, $variantId, $requestedQuantity, $context, $ttlMinutes) {
                return DB::transaction(function () use ($tenantId, $reservationKey, $productId, $variantId, $requestedQuantity, $context, $ttlMinutes) {
                    $existing = InventoryReservation::query()
                        ->where('tenant_id', $tenantId)
                        ->where('reservation_key', $reservationKey)
                        ->first();

                    if ($existing !== null) {
                        return new ReservationResultDTO(
                            isSuccess: false,
                            reservation: null,
                            reservedQuantity: Quantity::zero(),
                            message: "Reservation with key [{$reservationKey}] already exists for this tenant."
                        );
                    }

                    // 1. Fetch eligible sources via shared eligibility engine
                    $eligibleSourceIds = $this->eligibilityService->getEligibleSourceIds($context);

                    // 2. Fetch candidate StockItem IDs sorted deterministically (ASC)
                    $stockItemIds = StockItem::query()
                        ->where('tenant_id', $tenantId)
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
                    $backorderCandidate = null;
                    $availableBackorderCapacity = Quantity::zero();

                    foreach ($lockedItems as $item) {
                        if ($remainingToReserve->isZero()) {
                            break;
                        }

                        $ats = $item->getAvailableToSellQuantity();

                        // Evaluate backorder allowance
                        if ($item->backorder_mode === 'allow') {
                            $backorderCandidate = $item;
                            $availableBackorderCapacity = Quantity::fromString('9999999.0000');
                        } elseif ($item->backorder_mode === 'allow_with_limit' && $item->backorder_limit !== null) {
                            $limit = Quantity::fromString((string) $item->backorder_limit);
                            // Existing backordered = max(0, reserved - on_hand)
                            $currOnHand = Quantity::fromString((string) $item->on_hand);
                            $currReserved = Quantity::fromString((string) $item->reserved);
                            $existingBackorder = $currReserved->isGreaterThan($currOnHand) ? $currReserved->subtract($currOnHand) : Quantity::zero();
                            $remainingLimit = $limit->subtract($existingBackorder);

                            $backorderCandidate = $item;
                            $availableBackorderCapacity = $remainingLimit->isPositive() ? $remainingLimit : Quantity::zero();
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

                    // 4. Backorder remainder allocation with limit enforcement
                    if ($remainingToReserve->isPositive()) {
                        if ($backorderCandidate !== null) {
                            if ($remainingToReserve->isGreaterThan($availableBackorderCapacity)) {
                                return new ReservationResultDTO(
                                    isSuccess: false,
                                    reservation: null,
                                    reservedQuantity: Quantity::zero(),
                                    message: "Requested quantity exceeds available stock and backorder limit [{$availableBackorderCapacity->toString()}]."
                                );
                            }

                            $allocationsPlan[] = [
                                'stock_item' => $backorderCandidate,
                                'quantity' => $remainingToReserve,
                            ];
                            $remainingToReserve = Quantity::zero();
                        } else {
                            return new ReservationResultDTO(
                                isSuccess: false,
                                reservation: null,
                                reservedQuantity: Quantity::zero(),
                                message: "Insufficient available stock to satisfy requested quantity [{$requestedQuantity->toString()}]."
                            );
                        }
                    }

                    // 5. Create Parent Reservation
                    $reservation = InventoryReservation::create([
                        'tenant_id' => $tenantId,
                        'reservation_key' => $reservationKey,
                        'status' => 'active',
                        'expires_at' => Carbon::now()->addMinutes($ttlMinutes),
                    ]);

                    $recordedAllocations = [];

                    // 6. Create Child Allocations & update stock_items.reserved
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

                        // Check low stock / out of stock threshold crossing
                        $newAts = $stockItem->getAvailableToSellQuantity();
                        if ($newAts->isZero()) {
                            OutOfStockDetected::dispatch($stockItem);
                        } elseif ($stockItem->low_stock_threshold !== null) {
                            $thresh = Quantity::fromString((string) $stockItem->low_stock_threshold);
                            if ($newAts->isLessThanOrEqual($thresh)) {
                                LowStockDetected::dispatch($stockItem, $newAts);
                            }
                        }

                        $recordedAllocations[] = [
                            'stock_item_id' => $stockItem->id,
                            'source_id' => $stockItem->inventory_source_id,
                            'quantity' => $qty,
                        ];
                    }

                    InventoryReserved::dispatch($reservation);

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

    /**
     * Adopts an active reservation under an opaque typed owner.
     *
     * Transaction semantics (FOR UPDATE row lock):
     *  1. Lock the reservation row.
     *  2. Verify tenant ownership.
     *  3. Same-owner replay → return wasAlreadyAdopted = true (idempotent).
     *  4. Conflicting owner → throw ReservationAdoptionException::conflictingOwner().
     *  5. Require status = active.
     *  6. Require expires_at IS NULL OR expires_at > now (TTL-expiry gate):
     *     an active reservation that has already passed its TTL is semantically
     *     expired even if ExpireReservationsCommand has not yet processed it.
     *     Such a reservation MUST NOT be rescued by adoption.
     *  7. Write owner_type, owner_reference, adopted_at, expires_at = null.
     *
     * Idempotency:
     *  The natural owner-key pair (reservationKey + ownerType + ownerReference) is
     *  the idempotency fingerprint. There is no separate durable idempotency key
     *  table entry for adopt(), because:
     *   - The row lock + same-owner guard provides atomic, durable idempotency.
     *   - The existing InventoryIdempotencyService stores MovementResult payloads;
     *     ReservationAdoptionResultDTO is not serialisable into that schema.
     *   - Release and commit do not use idempotency keys either (same convention).
     *  The ?string $idempotencyKey parameter is intentionally absent from this method.
     *  Callers requiring a higher-level idempotency envelope should implement it at
     *  the calling layer (e.g. Order operation key table, PHASE-08 layer).
     *
     * Event dispatch:
     *  InventoryReservationAdopted is dispatched inside the transaction, consistent
     *  with all accepted Inventory events (InventoryReserved, InventoryReservationReleased,
     *  InventoryCommitted, InventoryReservationExpired). This is the established project
     *  convention. Consumers must be aware that synchronous listeners execute within
     *  the transaction boundary.
     *
     * @throws ReservationAdoptionException
     */
    public function adopt(
        int $tenantId,
        string $reservationKey,
        ReservationOwnerType $ownerType,
        string $ownerReference
    ): ReservationAdoptionResultDTO {
        return DB::transaction(function () use ($tenantId, $reservationKey, $ownerType, $ownerReference) {
            // Lock the row for update first.  The WHERE clause on tenant_id ensures
            // cross-tenant reservation keys are invisible (not-found), preventing
            // existence disclosure.  No separate cross-tenant guard is needed or
            // desirable — the not-found response is the correct security behaviour.
            /** @var InventoryReservation|null $reservation */
            $reservation = InventoryReservation::query()
                ->where('tenant_id', $tenantId)
                ->where('reservation_key', $reservationKey)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                // Covers: not found, already expired/deleted, or belongs to another tenant.
                // All cases return the same typed not-active response to avoid existence disclosure.
                throw ReservationAdoptionException::notActive($reservationKey, 'not_found');
            }

            // Guard 2: idempotent same-owner replay
            if ($reservation->isAdoptedBy($ownerType, $ownerReference)) {
                return new ReservationAdoptionResultDTO(
                    isSuccess: true,
                    reservation: $reservation,
                    message: 'Reservation already adopted by this owner.',
                    wasAlreadyAdopted: true,
                );
            }

            // Guard 3: conflicting owner
            if ($reservation->isAdopted()) {
                throw ReservationAdoptionException::conflictingOwner(
                    $reservationKey,
                    (string) $reservation->owner_type,
                    (string) $reservation->owner_reference,
                );
            }

            // Guard 4: status must be active
            if ($reservation->status !== 'active') {
                throw ReservationAdoptionException::notActive($reservationKey, $reservation->status);
            }

            // Guard 5: TTL-expiry gate — reject semantically expired reservations that have
            // not yet been processed by ExpireReservationsCommand.
            $now = Carbon::now();
            if ($reservation->expires_at !== null && $reservation->expires_at->lte($now)) {
                throw ReservationAdoptionException::ttlExpired($reservationKey);
            }

            // Adopt: transfer ownership; nullify TTL for indefinite retention.
            $reservation->owner_type = $ownerType->value;
            $reservation->owner_reference = $ownerReference;
            $reservation->adopted_at = $now;
            $reservation->expires_at = null; // indefinitely retained until commit or release
            $reservation->save();

            // Dispatch the adoption event after the transaction commits.
            // This guarantees no listener observes an adoption that rolls back.
            // DB::afterCommit() executes the callback when the outermost transaction
            // (transaction depth = 0) commits.  Under nested transactions it queues
            // the callback until the outermost commit.
            $reservationId = $reservation->id;
            DB::afterCommit(function () use ($reservationId): void {
                $committed = InventoryReservation::find($reservationId);
                if ($committed !== null) {
                    InventoryReservationAdopted::dispatch($committed);
                }
            });

            return new ReservationAdoptionResultDTO(
                isSuccess: true,
                reservation: $reservation,
                message: 'Reservation adopted successfully.',
                wasAlreadyAdopted: false,
            );
        });
    }

    public function release(int $tenantId, string $reservationKey, ?string $idempotencyKey = null): bool
    {
        return DB::transaction(function () use ($tenantId, $reservationKey) {
            /** @var InventoryReservation|null $reservation */
            $reservation = InventoryReservation::query()
                ->where('tenant_id', $tenantId)
                ->where('reservation_key', $reservationKey)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return false;
            }

            foreach ($reservation->allocations as $alloc) {
                /** @var InventoryReservationAllocation $alloc */
                /** @var StockItem|null $stockItem */
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

            InventoryReservationReleased::dispatch($reservation);

            return true;
        });
    }

    public function commit(int $tenantId, string $reservationKey, ?string $idempotencyKey = null): bool
    {
        return DB::transaction(function () use ($tenantId, $reservationKey) {
            /** @var InventoryReservation|null $reservation */
            $reservation = InventoryReservation::query()
                ->where('tenant_id', $tenantId)
                ->where('reservation_key', $reservationKey)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                return false;
            }

            foreach ($reservation->allocations as $alloc) {
                /** @var InventoryReservationAllocation $alloc */
                /** @var StockItem|null $stockItem */
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

            InventoryCommitted::dispatch($reservation);

            return true;
        });
    }

    /**
     * Expires a reservation, releasing its stock back from reserved.
     *
     * AUTHORITY INVARIANT: expire() is the authoritative decision-maker for
     * automatic expiration. The ExpireReservationsCommand query is a candidate
     * optimisation only — it narrows the working set. expire() re-evaluates ALL
     * eligibility predicates under its own FOR UPDATE row lock, after which no
     * concurrent adopt(), release(), or commit() can change the row's state.
     *
     * Eligibility re-evaluated under the row lock (TOCTOU-safe):
     *  1. status = active     (not committed/released/already expired)
     *  2. owner_type IS NULL  (not adopted — adopted reservations must never
     *                          be expired by the automatic sweep)
     *  3. expires_at IS NOT NULL (sanity: un-adopted reservations always have a TTL)
     *  4. expires_at <= now() (TTL has genuinely passed at the time of the lock)
     *
     * If ANY predicate fails, expire() returns false (no-op) without side effects.
     *
     * Race safety:
     *  - adopt() also acquires FOR UPDATE on the same row before writing owner_type.
     *  - PostgreSQL serialises the two transactions on the row lock.
     *  - Whichever transaction commits first wins; the loser sees the updated state
     *    inside its own lock and aborts cleanly.
     */
    public function expire(InventoryReservation $reservation): bool
    {
        return DB::transaction(function () use ($reservation) {
            // Re-fetch the row under a FOR UPDATE lock.  This is the authoritative
            // state check — the command-level candidate query is NOT trusted.
            /** @var InventoryReservation|null $locked */
            $locked = InventoryReservation::query()
                ->where('id', $reservation->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return false;
            }

            // Authoritative eligibility: ALL four predicates must hold.
            $now = Carbon::now();
            if (! $locked->isEligibleForAutomaticExpiration($now)) {
                // Pre-empted by adopt(), release(), commit(), or a concurrent
                // expire() — this is expected; return no-op.
                return false;
            }

            foreach ($locked->allocations as $alloc) {
                /** @var InventoryReservationAllocation $alloc */
                /** @var StockItem|null $stockItem */
                $stockItem = StockItem::query()->where('id', $alloc->stock_item_id)->lockForUpdate()->first();
                if ($stockItem !== null) {
                    $allocQty = Quantity::fromString((string) $alloc->quantity);
                    $currentReserved = Quantity::fromString((string) $stockItem->reserved);
                    $newReserved = $currentReserved->subtract($allocQty);
                    $stockItem->reserved = $newReserved->isNegative() ? '0.0000' : $newReserved->toString();
                    $stockItem->save();
                }
            }

            $locked->status = 'expired';
            $locked->save();

            InventoryReservationExpired::dispatch($locked);

            return true;
        });
    }
}
