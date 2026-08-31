<?php

declare(strict_types=1);

namespace Modules\Inventory\Contracts;

use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\DTOs\ReservationResultDTO;
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

    public function release(int $tenantId, string $reservationKey, ?string $idempotencyKey = null): bool;

    public function commit(int $tenantId, string $reservationKey, ?string $idempotencyKey = null): bool;

    public function expire(InventoryReservation $reservation): bool;
}
