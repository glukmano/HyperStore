<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Modules\Marketplace\Models\VendorListing;

interface VendorListingResolutionServiceInterface
{
    public function resolveListingByUuid(
        int $tenantId,
        int $storeId,
        string $vendorListingUuid,
        ?int $productId = null,
        ?int $variantId = null
    ): ?VendorListing;

    public function assertListingAvailable(int $tenantId, int $storeId, int $listingId): void;
}
