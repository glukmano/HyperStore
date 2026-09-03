<?php

declare(strict_types=1);

namespace Modules\Marketplace\Enums;

enum VendorPayableAvailabilityStatus: string
{
    case Pending = 'pending';
    case Available = 'available';
    case Held = 'held';

    public function isAvailable(): bool
    {
        return $this === self::Available;
    }
}
