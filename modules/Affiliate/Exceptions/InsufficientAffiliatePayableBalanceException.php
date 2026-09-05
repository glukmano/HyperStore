<?php

declare(strict_types=1);

namespace Modules\Affiliate\Exceptions;

final class InsufficientAffiliatePayableBalanceException extends AffiliateException
{
    public static function forAmount(int $requestedMinor, int $availableMinor, string $currency): self
    {
        return new self("Requested payout of {$requestedMinor} {$currency} exceeds available withdrawable balance of {$availableMinor} {$currency}.");
    }
}
