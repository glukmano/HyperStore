<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Modules\Marketplace\Models\VendorListing;

interface VendorListingCreationServiceInterface
{
    /**
     * @param array{
     *     product_id: int,
     *     product_variant_id?: int|null,
     *     vendor_sku: string,
     *     store_ids?: array<int>,
     *     status?: string
     * } $attributes
     */
    public function createListing(int $tenantId, int $vendorId, array $attributes): VendorListing;
}
