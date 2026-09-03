<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Models\Product;
use Modules\Marketplace\Contracts\VendorListingResolutionServiceInterface;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorListing;
use Modules\Marketplace\Models\VendorListingStoreAvailability;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Models\VendorStoreParticipation;
use Tests\TestCase;

class VendorListingStoreAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $storeA;

    private Store $storeB;

    private Vendor $vendor;

    private Product $product;

    private VendorListing $listing;

    private VendorListingResolutionServiceInterface $resolutionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Store Test Tenant', 'slug' => 'store-test']);
        $this->storeA = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store A', 'slug' => 'store-a']);
        $this->storeB = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Store B', 'slug' => 'store-b']);

        $plan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Listing Plan',
            'code' => 'listing-plan',
        ]);

        $this->vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'Listing Vendor',
            'platform_slug' => 'listing-vendor',
            'legal_name' => 'Listing Vendor Corp',
            'email' => 'list@vendor.com',
            'operational_status' => VendorOperationalStatus::Active,
        ]);

        // Vendor participates in BOTH Store A and Store B
        VendorStoreParticipation::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'store_id' => $this->storeA->id,
            'is_enabled' => true,
        ]);
        VendorStoreParticipation::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'store_id' => $this->storeB->id,
            'is_enabled' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'product_type' => 'simple',
            'sku' => 'CANONICAL-PROD-1',
            'status' => 'active',
        ]);

        // Listing created for product
        $this->listing = VendorListing::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'product_id' => $this->product->id,
            'product_variant_id' => null,
            'vendor_sku' => 'V-SKU-1',
        ]);

        $this->resolutionService = app(VendorListingResolutionServiceInterface::class);
    }

    public function test_listing_enabled_for_store_a_resolves_cleanly(): void
    {
        VendorListingStoreAvailability::create([
            'tenant_id' => $this->tenant->id,
            'vendor_listing_id' => $this->listing->id,
            'store_id' => $this->storeA->id,
            'is_enabled' => true,
        ]);

        $resolved = $this->resolutionService->resolveListing(
            tenantId: $this->tenant->id,
            storeId: $this->storeA->id,
            productId: $this->product->id
        );

        $this->assertNotNull($resolved);
        $this->assertSame($this->listing->id, $resolved->id);
    }

    public function test_listing_disabled_for_store_b_does_not_resolve_even_though_vendor_participates(): void
    {
        // Explicitly disabled for Store B
        VendorListingStoreAvailability::create([
            'tenant_id' => $this->tenant->id,
            'vendor_listing_id' => $this->listing->id,
            'store_id' => $this->storeB->id,
            'is_enabled' => false,
        ]);

        $resolved = $this->resolutionService->resolveListing(
            tenantId: $this->tenant->id,
            storeId: $this->storeB->id,
            productId: $this->product->id
        );

        $this->assertNull($resolved);
    }

    public function test_inactive_vendor_listing_cannot_sell_in_any_store(): void
    {
        VendorListingStoreAvailability::create([
            'tenant_id' => $this->tenant->id,
            'vendor_listing_id' => $this->listing->id,
            'store_id' => $this->storeA->id,
            'is_enabled' => true,
        ]);

        // Suspend vendor
        $this->vendor->operational_status = VendorOperationalStatus::Suspended;
        $this->vendor->save();

        $resolved = $this->resolutionService->resolveListing(
            tenantId: $this->tenant->id,
            storeId: $this->storeA->id,
            productId: $this->product->id
        );

        $this->assertNull($resolved);
    }
}
