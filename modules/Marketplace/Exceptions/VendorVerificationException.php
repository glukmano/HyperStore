<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class VendorVerificationException extends MarketplaceException
{
    public static function invalidTransition(string $from, string $to): self
    {
        return new self("Cannot transition vendor verification status from '{$from}' to '{$to}'.");
    }
}
