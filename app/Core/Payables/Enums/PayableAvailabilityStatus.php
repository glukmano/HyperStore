<?php

declare(strict_types=1);

namespace App\Core\Payables\Enums;

enum PayableAvailabilityStatus: string
{
    case Pending = 'pending';
    case Available = 'available';
    case Held = 'held';

    public function isAvailable(): bool
    {
        return $this === self::Available;
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => in_array($target, [self::Available, self::Held], true),
            self::Available => $target === self::Held,
            self::Held => in_array($target, [self::Available, self::Pending], true),
        };
    }
}
