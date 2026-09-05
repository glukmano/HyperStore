<?php

declare(strict_types=1);

namespace Modules\Affiliate\Exceptions;

final class AffiliatePayoutAllocationException extends AffiliateException
{
    public static function allocationMismatch(int $requested, int $allocated): self
    {
        return new self("Payout reservation mismatch: requested {$requested} minor units, but allocated {$allocated} minor units.");
    }
}
