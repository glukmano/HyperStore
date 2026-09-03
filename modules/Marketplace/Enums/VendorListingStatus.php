<?php

declare(strict_types=1);

namespace Modules\Marketplace\Enums;

enum VendorListingStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
