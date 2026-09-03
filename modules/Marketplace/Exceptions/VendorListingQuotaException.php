<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class VendorListingQuotaException extends MarketplaceException
{
    public static function quotaExceeded(int $limit): self
    {
        return new self("Vendor has reached maximum product listing limit ({$limit}).");
    }
}
