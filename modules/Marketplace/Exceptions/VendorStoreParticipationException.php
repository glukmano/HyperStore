<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class VendorStoreParticipationException extends MarketplaceException
{
    public static function vendorNotParticipating(int $vendorId, int $storeId): self
    {
        return new self("Vendor [{$vendorId}] does not actively participate in store [{$storeId}].");
    }
}
