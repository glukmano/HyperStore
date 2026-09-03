<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Modules\Marketplace\Contracts\VendorListingResolutionServiceInterface;
use Modules\Marketplace\Enums\VendorListingStatus;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Exceptions\CrossTenantMarketplaceException;
use Modules\Marketplace\Exceptions\VendorListingStoreAvailabilityException;
use Modules\Marketplace\Models\VendorListing;
use Modules\Marketplace\Models\VendorListingStoreAvailability;
use Modules\Marketplace\Models\VendorStoreParticipation;

final class VendorListingResolutionService implements VendorListingResolutionServiceInterface
{
    public function resolveListing(int $tenantId, int $storeId, int $productId, ?int $variantId = null): ?VendorListing
    {
        $query = VendorListing::where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->where('status', VendorListingStatus::Active->value);

        if ($variantId !== null) {
            $query->where('product_variant_id', $variantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        /** @var VendorListing|null $listing */
        $listing = $query->first();
        if ($listing === null) {
            return null;
        }

        // Verify vendor is active
        $vendor = $listing->vendor;
        if ($vendor->operational_status !== VendorOperationalStatus::Active) {
            return null;
        }

        // Verify vendor participates in store
        $participating = VendorStoreParticipation::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendor->id)
            ->where('store_id', $storeId)
            ->where('is_enabled', true)
            ->exists();

        if (! $participating) {
            return null;
        }

        // Verify listing is enabled in store
        $enabledInStore = VendorListingStoreAvailability::where('tenant_id', $tenantId)
            ->where('vendor_listing_id', $listing->id)
            ->where('store_id', $storeId)
            ->where('is_enabled', true)
            ->exists();

        if (! $enabledInStore) {
            return null;
        }

        return $listing;
    }

    public function assertListingAvailable(int $tenantId, int $storeId, int $listingId): void
    {
        /** @var VendorListing|null $listing */
        $listing = VendorListing::find($listingId);
        if ($listing === null || $listing->tenant_id !== $tenantId) {
            throw CrossTenantMarketplaceException::listingMismatch($listing !== null ? $listing->tenant_id : 0, $tenantId);
        }

        $participating = VendorStoreParticipation::where('tenant_id', $tenantId)
            ->where('vendor_id', $listing->vendor_id)
            ->where('store_id', $storeId)
            ->where('is_enabled', true)
            ->exists();

        if (! $participating) {
            throw VendorListingStoreAvailabilityException::vendorNotParticipatingInStore($listing->vendor_id, $storeId);
        }

        $enabledInStore = VendorListingStoreAvailability::where('tenant_id', $tenantId)
            ->where('vendor_listing_id', $listing->id)
            ->where('store_id', $storeId)
            ->where('is_enabled', true)
            ->exists();

        if (! $enabledInStore) {
            throw VendorListingStoreAvailabilityException::listingDisabledInStore($listing->id, $storeId);
        }
    }
}
