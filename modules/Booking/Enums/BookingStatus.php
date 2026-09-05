<?php

declare(strict_types=1);

namespace Modules\Booking\Enums;

enum BookingStatus: string
{
    case Held = 'held';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
}
