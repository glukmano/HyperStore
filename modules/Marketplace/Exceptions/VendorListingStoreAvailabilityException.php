<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class VendorListingStoreAvailabilityException extends MarketplaceException
{
    public static function vendorNotParticipatingInStore(int $vendorId, int $storeId): self
    {
        return new self("Vendor {$vendorId} does not participate in store {$storeId}; listing cannot be enabled in this store.");
    }

    public static function listingDisabledInStore(int $listingId, int $storeId): self
    {
        return new self("Listing {$listingId} is not enabled for store {$storeId}.");
    }
}
