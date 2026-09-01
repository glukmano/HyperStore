<?php

declare(strict_types=1);

namespace Modules\Inventory\Exceptions;

use RuntimeException;

final class ReservationAdoptionException extends RuntimeException
{
    public static function notActive(string $reservationKey, string $status): self
    {
        return new self("RESERVATION_NOT_ACTIVE: Reservation [{$reservationKey}] has status [{$status}] and cannot be adopted.");
    }

    public static function conflictingOwner(string $reservationKey, string $existingOwnerType, string $existingOwnerRef): self
    {
        return new self("RESERVATION_CONFLICTING_OWNER: Reservation [{$reservationKey}] is already adopted by [{$existingOwnerType}:{$existingOwnerRef}]. Adoption by a different owner is rejected.");
    }

    public static function crossTenant(string $reservationKey): self
    {
        return new self("RESERVATION_CROSS_TENANT: Reservation [{$reservationKey}] does not belong to the specified tenant.");
    }

    public static function ttlExpired(string $reservationKey): self
    {
        return new self("RESERVATION_TTL_EXPIRED: Reservation [{$reservationKey}] has status=active but its TTL has already passed. It is semantically expired and cannot be adopted.");
    }
}
