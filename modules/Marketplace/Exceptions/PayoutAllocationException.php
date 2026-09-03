<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class PayoutAllocationException extends MarketplaceException
{
    public static function crossCurrencyForbidden(string $payoutCurrency, string $entryCurrency): self
    {
        return new self("Cross-currency payout allocation is prohibited: payout is in {$payoutCurrency}, but source entry is in {$entryCurrency}.");
    }

    public static function invalidSourceType(string $type): self
    {
        return new self("Cannot allocate payout against non-credit entry type '{$type}'.");
    }

    public static function allocationMismatch(int $requested, int $allocated): self
    {
        return new self("Payout reservation mismatch: requested {$requested} minor units, but allocated {$allocated} minor units.");
    }
}
