<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class VendorOperationalStatusException extends MarketplaceException
{
    public static function invalidTransition(string $from, string $to): self
    {
        return new self("Cannot transition vendor operational status from '{$from}' to '{$to}'.");
    }

    public static function vendorNotActive(string $vendorUuid, string $currentStatus): self
    {
        return new self("Vendor '{$vendorUuid}' is not active (current status: '{$currentStatus}').");
    }
}
