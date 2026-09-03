<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use App\Core\Stores\Models\Store;
use Illuminate\Support\Facades\DB;
use Modules\Marketplace\Contracts\MarketplaceConcurrencyBarrierInterface;
use Modules\Marketplace\Contracts\VendorListingStoreAvailabilityServiceInterface;
use Modules\Marketplace\Exceptions\CrossTenantMarketplaceException;
use Modules\Marketplace\Exceptions\VendorStoreParticipationException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorListing;
use Modules\Marketplace\Models\VendorListingStoreAvailability;
use Modules\Marketplace\Models\VendorStoreParticipation;

final readonly class VendorListingStoreAvailabilityService implements VendorListingStoreAvailabilityServiceInterface
{
    public function __construct(
        private MarketplaceConcurrencyBarrierInterface $barrier = new NoOpMarketplaceConcurrencyBarrier
    ) {}

    public function setListingStoreAvailability(
        int $tenantId,
        int $vendorListingId,
        int $storeId,
        bool $isEnabled
    ): VendorListingStoreAvailability {
        return DB::transaction(function () use ($tenantId, $vendorListingId, $storeId, $isEnabled): VendorListingStoreAvailability {
            /** @var VendorListing $listing */
            $listing = VendorListing::where('tenant_id', $tenantId)->findOrFail($vendorListingId);

            /** @var Vendor $vendor */
            $vendor = Vendor::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($listing->vendor_id);

            $store = Store::find($storeId);
            if ($store === null || $store->tenant_id !== $tenantId) {
                throw new CrossTenantMarketplaceException("Store [{$storeId}] does not belong to tenant [{$tenantId}].");
            }

            if ($isEnabled) {
                $participating = VendorStoreParticipation::where('tenant_id', $tenantId)
                    ->where('vendor_id', $vendor->id)
                    ->where('store_id', $store->id)
                    ->where('is_enabled', true)
                    ->exists();

                if (! $participating) {
                    throw VendorStoreParticipationException::vendorNotParticipating($vendor->id, $store->id);
                }
            }

            $this->barrier->wait('vendor_listing_store_availability_mutating');

            /** @var VendorListingStoreAvailability $availability */
            $availability = VendorListingStoreAvailability::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'vendor_listing_id' => $listing->id,
                    'store_id' => $store->id,
                ],
                [
                    'is_enabled' => $isEnabled,
                ]
            );

            return $availability;
        });
    }
}
