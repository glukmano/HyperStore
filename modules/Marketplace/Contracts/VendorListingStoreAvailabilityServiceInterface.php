<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Modules\Marketplace\Models\VendorListingStoreAvailability;

interface VendorListingStoreAvailabilityServiceInterface
{
    public function setListingStoreAvailability(
        int $tenantId,
        int $vendorListingId,
        int $storeId,
        bool $isEnabled
    ): VendorListingStoreAvailability;
}
