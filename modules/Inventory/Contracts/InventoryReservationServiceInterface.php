<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\DTOs\ReservationAdoptionResultDTO;
use Modules\Inventory\DTOs\ReservationResultDTO;
use Modules\Inventory\Enums\ReservationOwnerType;
use Modules\Inventory\Exceptions\ReservationAdoptionException;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\ValueObjects\Quantity;

interface InventoryReservationServiceInterface
{
    public function reserve(
        int $tenantId,
        string $reservationKey,
        int $productId,
        ?int $variantId,
        Quantity $requestedQuantity,
        InventoryContext $context,
        int $ttlMinutes = 15,
        ?string $idempotencyKey = null
    ): ReservationResultDTO;

    /**
     * Adopts an active reservation under an opaque typed owner (e.g. an Order).
     *
     * Semantics:
     *  - Reservation must be status = active AND not TTL-expired (expires_at > now).
     *  - Ownership type and reference are stored in Inventory-owned columns (no Order FK).
     *  - expires_at is set to null (indefinite retention) upon adoption.
     *  - ExpireReservationsCommand will not touch adopted reservations (owner_type IS NOT NULL).
     *  - Existing allocations and StockItem.reserved amounts are unchanged.
     *  - Idempotent: same owner + same reservationKey returns wasAlreadyAdopted = true.
     *  - Conflicting owner (different owner_reference) is rejected with a typed exception.
     *  - Cross-tenant reservation keys are invisible: treated as not-found (no existence disclosure).
     *
     * Event dispatch:
     *  InventoryReservationAdopted is dispatched after the transaction commits (DB::afterCommit).
     *  No listener observes an adoption that was rolled back.
     *
     * Idempotency:
     *  No external idempotency key parameter. The natural composite fingerprint
     *  (tenantId + reservationKey + ownerType + ownerReference) provides durable idempotency
     *  via the FOR UPDATE row lock. Callers requiring operation-key-level idempotency must
     *  implement it at the calling layer (e.g. Order module operation key table).
     *
     * @throws ReservationAdoptionException
     */
    public function adopt(
        int $tenantId,
        string $reservationKey,
        ReservationOwnerType $ownerType,
        string $ownerReference
    ): ReservationAdoptionResultDTO;

    public function release(int $tenantId, string $reservationKey, ?string $idempotencyKey = null): bool;

    public function commit(int $tenantId, string $reservationKey, ?string $idempotencyKey = null): bool;

    /**
     * Authoritatively expires a reservation, releasing its reserved stock.
     *
     * This method is the sole decision-maker for automatic expiration.
     * The ExpireReservationsCommand candidate query is an optimisation only.
     * Eligibility is re-evaluated under a FOR UPDATE row lock inside expire():
     *  - status = active
     *  - owner_type IS NULL  (adopted reservations are never expired automatically)
     *  - expires_at IS NOT NULL
     *  - expires_at <= now()
     *
     * Returns false (no-op) if any predicate fails — e.g. adopt() committed first.
     */
    public function expire(InventoryReservation $reservation): bool;
}
