<?php

declare(strict_types=1);

namespace Modules\Marketplace\Enums;

enum VendorDomainStatus: string
{
    case Requested = 'requested';
    case VerificationPending = 'verification_pending';
    case Verified = 'verified';
    case Active = 'active';
    case Revoked = 'revoked';

    public function isRoutable(): bool
    {
        return $this === self::Active;
    }

    public function isVerified(): bool
    {
        return in_array($this, [self::Verified, self::Active], true);
    }
}
