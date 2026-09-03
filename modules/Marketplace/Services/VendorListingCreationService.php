<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use App\Core\Stores\Models\Store;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use Modules\Marketplace\Contracts\MarketplaceConcurrencyBarrierInterface;
use Modules\Marketplace\Contracts\VendorListingCreationServiceInterface;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Exceptions\CrossTenantMarketplaceException;
use Modules\Marketplace\Exceptions\VendorListingQuotaException;
use Modules\Marketplace\Exceptions\VendorOperationalStatusException;
use Modules\Marketplace\Exceptions\VendorStoreParticipationException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorListing;
use Modules\Marketplace\Models\VendorListingStoreAvailability;
use Modules\Marketplace\Models\VendorStoreParticipation;

final readonly class VendorListingCreationService implements VendorListingCreationServiceInterface
{
    public function __construct(
        private MarketplaceConcurrencyBarrierInterface $barrier = new NoOpMarketplaceConcurrencyBarrier
    ) {}

    public function createListing(int $tenantId, int $vendorId, array $attributes): VendorListing
    {
        return DB::transaction(function () use ($tenantId, $vendorId, $attributes): VendorListing {
            // 1 & 2. Resolve Vendor and acquire row lock
            /** @var Vendor $vendor */
            $vendor = Vendor::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($vendorId);

            // 3. Validate Vendor operational eligibility
            if ($vendor->operational_status !== VendorOperationalStatus::Active) {
                throw VendorOperationalStatusException::vendorNotActive(
                    $vendor->uuid,
                    $vendor->operational_status->value
                );
            }

            // 4. Resolve current authoritative Vendor Plan while Vendor lock is held
            $plan = $vendor->plan;

            // 5. Count quota-relevant Vendor listings
            $currentListingCount = VendorListing::where('tenant_id', $tenantId)
                ->where('vendor_id', $vendorId)
                ->count();

            // 6. Enforce product listing limit if defined by plan
            if ($plan !== null && $plan->product_limit !== null) {
                if ($currentListingCount >= $plan->product_limit) {
                    throw VendorListingQuotaException::quotaExceeded($plan->product_limit);
                }
            }

            $this->barrier->wait('vendor_listing_quota_verified');

            // 7. Validate Catalog Product/Variant tenant identity
            $productId = (int) $attributes['product_id'];
            /** @var Product $product */
            $product = Product::where('tenant_id', $tenantId)->findOrFail($productId);

            $variantId = isset($attributes['product_variant_id'])
                ? (int) $attributes['product_variant_id']
                : null;

            if ($variantId !== null) {
                ProductVariant::where('tenant_id', $tenantId)
                    ->where('product_id', $product->id)
                    ->findOrFail($variantId);
            }

            // 8. Validate Store participation/entitlement if store scope provided
            $storeIds = isset($attributes['store_ids']) && is_array($attributes['store_ids'])
                ? $attributes['store_ids']
                : [];

            foreach ($storeIds as $storeId) {
                $store = Store::find($storeId);
                if ($store === null || $store->tenant_id !== $tenantId) {
                    throw new CrossTenantMarketplaceException("Store [{$storeId}] does not belong to tenant [{$tenantId}].");
                }

                $participating = VendorStoreParticipation::where('tenant_id', $tenantId)
                    ->where('vendor_id', $vendorId)
                    ->where('store_id', (int) $storeId)
                    ->where('is_enabled', true)
                    ->exists();

                if (! $participating) {
                    throw VendorStoreParticipationException::vendorNotParticipating($vendorId, (int) $storeId);
                }
            }

            // 9. Create VendorListing while Vendor lock is still held
            /** @var VendorListing $listing */
            $listing = VendorListing::create([
                'tenant_id' => $tenantId,
                'vendor_id' => $vendorId,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'vendor_sku' => (string) $attributes['vendor_sku'],
                'status' => $attributes['status'] ?? 'active',
            ]);

            foreach ($storeIds as $storeId) {
                VendorListingStoreAvailability::create([
                    'tenant_id' => $tenantId,
                    'vendor_listing_id' => $listing->id,
                    'store_id' => (int) $storeId,
                    'is_enabled' => true,
                ]);
            }

            return $listing;
        });
    }
}
