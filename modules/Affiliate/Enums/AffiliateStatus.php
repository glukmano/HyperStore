<?php

declare(strict_types=1);

namespace Modules\Affiliate\Enums;

enum AffiliateStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Rejected = 'rejected';

    public function isEligibleForPayout(): bool
    {
        return $this === self::Active;
    }
}
