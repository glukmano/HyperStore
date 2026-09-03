<?php

declare(strict_types=1);

namespace Modules\Marketplace\Enums;

enum VendorInvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function isPending(): bool
    {
        return $this === self::Pending;
    }
}
