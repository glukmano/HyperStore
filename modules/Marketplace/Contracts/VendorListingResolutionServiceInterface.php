<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Modules\Marketplace\Models\VendorListing;

interface VendorListingResolutionServiceInterface
{
    public function resolveListing(int $tenantId, int $storeId, int $productId, ?int $variantId = null): ?VendorListing;

    public function assertListingAvailable(int $tenantId, int $storeId, int $listingId): void;
}
