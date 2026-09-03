<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class CommissionCalculationException extends MarketplaceException
{
    public static function negativeBasis(int $basis): self
    {
        return new self("Commission basis cannot be negative (got: {$basis}).");
    }

    public static function invalidRateBps(int $bps): self
    {
        return new self("Commission rate basis points must be between 0 and 10000 (got: {$bps}).");
    }

    public static function currencyMismatch(string $expected, string $actual): self
    {
        return new self("Commission fixed fee currency '{$actual}' does not match line item currency '{$expected}'.");
    }

    public static function noRuleMatched(): self
    {
        return new self('No commission rule could be resolved for the given scope.');
    }
}
