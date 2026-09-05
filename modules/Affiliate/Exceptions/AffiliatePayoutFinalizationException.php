<?php

declare(strict_types=1);

namespace Modules\Affiliate\Exceptions;

final class AffiliatePayoutFinalizationException extends AffiliateException
{
    public static function notProcessing(string $status): self
    {
        return new self("Payout request cannot be finalized because status is '{$status}' (expected 'processing').");
    }

    public static function allocationsNotReserved(): self
    {
        return new self("Payout finalization failed: one or more allocations are not in 'reserved' status.");
    }
}
