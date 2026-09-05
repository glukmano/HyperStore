<?php

declare(strict_types=1);

namespace Modules\Booking\Exceptions;

final class SlotCapacityExceededException extends BookingException
{
    public static function forSlot(int $slotId): self
    {
        return new self("BookingSlot [{$slotId}] has no remaining capacity.");
    }
}
