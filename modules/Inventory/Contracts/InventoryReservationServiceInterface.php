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
     *  - Reservation must be status = active.
     *  - Reservation must not be TTL-expired (expires_at > now) even if status is still active.
     *  - Ownership type and reference are stored in Inventory-owned columns (no Order FK).
     *  - expires_at is set to null (indefinite retention) upon adoption.
     *  - ExpireReservationsCommand will not touch adopted reservations (owner_type IS NOT NULL).
     *  - Existing allocations and StockItem.reserved amounts are unchanged.
     *  - Idempotent: same owner + same reservationKey returns wasAlreadyAdopted = true.
     *  - Conflicting owner (different owner_reference for same owner_type) is rejected.
     *
     * Idempotency note:
     *  No external idempotency key parameter is accepted. The natural composite fingerprint
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

    public function expire(InventoryReservation $reservation): bool;
}
