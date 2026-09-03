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
    public function resolveListingByUuid(
        int $tenantId,
        int $storeId,
        string $vendorListingUuid,
        ?int $productId = null,
        ?int $variantId = null
    ): ?VendorListing {
        /** @var VendorListing|null $listing */
        $listing = VendorListing::where('tenant_id', $tenantId)
            ->where('uuid', $vendorListingUuid)
            ->first();

        if ($listing === null) {
            return null;
        }

        if ($listing->status !== VendorListingStatus::Active) {
            return null;
        }

        if ($productId !== null && $listing->product_id !== $productId) {
            return null;
        }

        if ($variantId !== null) {
            if ($listing->product_variant_id !== $variantId) {
                return null;
            }
        } elseif ($productId !== null && $listing->product_variant_id !== null) {
            return null;
        }

        $vendor = $listing->vendor;
        if ($vendor->operational_status !== VendorOperationalStatus::Active) {
            return null;
        }

        $participating = VendorStoreParticipation::where('tenant_id', $tenantId)
            ->where('vendor_id', $vendor->id)
            ->where('store_id', $storeId)
            ->where('is_enabled', true)
            ->exists();

        if (! $participating) {
            return null;
        }

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
