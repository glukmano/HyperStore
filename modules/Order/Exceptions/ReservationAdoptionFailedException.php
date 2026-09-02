<?php

declare(strict_types=1);

namespace Modules\Order\Exceptions;

use RuntimeException;

class ReservationAdoptionFailedException extends RuntimeException
{
    public static function forReservation(string $reservationKey, string $reason): self
    {
        return new self("Failed to adopt reservation [{$reservationKey}]: {$reason}", 422);
    }

    public static function forKey(string $reservationKey, string $reason): self
    {
        return self::forReservation($reservationKey, $reason);
    }
}
