<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Models\Product;
use Modules\Marketplace\Contracts\VendorListingCreationServiceInterface;
use Modules\Marketplace\Contracts\VendorListingResolutionServiceInterface;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Exceptions\VendorStoreParticipationException;
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

        $resolved = $this->resolutionService->resolveListingByUuid(
            tenantId: $this->tenant->id,
            storeId: $this->storeA->id,
            vendorListingUuid: $this->listing->uuid,
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

        $resolved = $this->resolutionService->resolveListingByUuid(
            tenantId: $this->tenant->id,
            storeId: $this->storeB->id,
            vendorListingUuid: $this->listing->uuid,
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

        $resolved = $this->resolutionService->resolveListingByUuid(
            tenantId: $this->tenant->id,
            storeId: $this->storeA->id,
            vendorListingUuid: $this->listing->uuid,
            productId: $this->product->id
        );

        $this->assertNull($resolved);
    }

    public function test_scenario_a_listing_creation_fails_closed_when_vendor_has_no_participation_in_store(): void
    {
        $vendorNoPart = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $this->vendor->vendor_plan_id,
            'name' => 'No Part Vendor',
            'platform_slug' => 'no-part-'.uniqid(),
            'legal_name' => 'No Part Corp',
            'email' => 'nopart@vendor.com',
            'operational_status' => VendorOperationalStatus::Active,
        ]);

        $service = app(VendorListingCreationServiceInterface::class);

        $this->expectException(VendorStoreParticipationException::class);

        $service->createListing(
            $this->tenant->id,
            $vendorNoPart->id,
            [
                'product_id' => $this->product->id,
                'product_variant_id' => null,
                'vendor_sku' => 'SKU-NO-PART',
                'store_ids' => [$this->storeA->id],
            ]
        );
    }

    public function test_scenario_b_listing_creation_succeeds_when_vendor_actively_participates_in_store(): void
    {
        $service = app(VendorListingCreationServiceInterface::class);

        $listing = $service->createListing(
            $this->tenant->id,
            $this->vendor->id,
            [
                'product_id' => $this->product->id,
                'product_variant_id' => null,
                'vendor_sku' => 'SKU-ACTIVE-PART-1',
                'store_ids' => [$this->storeA->id],
            ]
        );

        $this->assertNotNull($listing);
        $this->assertDatabaseHas('vendor_listing_store_availabilities', [
            'tenant_id' => $this->tenant->id,
            'vendor_listing_id' => $listing->id,
            'store_id' => $this->storeA->id,
            'is_enabled' => true,
        ]);
    }

    public function test_scenario_c_listing_creation_fails_closed_when_participation_is_disabled(): void
    {
        // Disable participation for Store A
        VendorStoreParticipation::where('tenant_id', $this->tenant->id)
            ->where('vendor_id', $this->vendor->id)
            ->where('store_id', $this->storeA->id)
            ->update(['is_enabled' => false]);

        $service = app(VendorListingCreationServiceInterface::class);

        $initialListingCount = VendorListing::where('tenant_id', $this->tenant->id)->where('vendor_id', $this->vendor->id)->count();

        $thrown = false;
        try {
            $service->createListing(
                $this->tenant->id,
                $this->vendor->id,
                [
                    'product_id' => $this->product->id,
                    'product_variant_id' => null,
                    'vendor_sku' => 'SKU-DISABLED-PART',
                    'store_ids' => [$this->storeA->id],
                ]
            );
        } catch (VendorStoreParticipationException) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'Expected VendorStoreParticipationException was not thrown.');
        $this->assertSame(
            $initialListingCount,
            VendorListing::where('tenant_id', $this->tenant->id)->where('vendor_id', $this->vendor->id)->count(),
            'Entire listing creation transaction must roll back when participation is disabled.'
        );
        $this->assertDatabaseMissing('vendor_listing_store_availabilities', [
            'vendor_sku' => 'SKU-DISABLED-PART',
        ]);
    }

    public function test_scenario_d_listing_creation_fails_closed_when_vendor_participates_in_store_a_but_requests_store_b_without_participation(): void
    {
        // Vendor with ONLY Store A participation
        $vendorAOnly = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $this->vendor->vendor_plan_id,
            'name' => 'Store A Only Vendor',
            'platform_slug' => 'store-a-only-'.uniqid(),
            'legal_name' => 'Store A Only Corp',
            'email' => 'storeaonly@vendor.com',
            'operational_status' => VendorOperationalStatus::Active,
        ]);

        VendorStoreParticipation::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $vendorAOnly->id,
            'store_id' => $this->storeA->id,
            'is_enabled' => true,
        ]);

        $service = app(VendorListingCreationServiceInterface::class);

        $this->expectException(VendorStoreParticipationException::class);

        // Request Store B in the same tenant (same-tenant store ownership alone is NOT sufficient!)
        $service->createListing(
            $this->tenant->id,
            $vendorAOnly->id,
            [
                'product_id' => $this->product->id,
                'product_variant_id' => null,
                'vendor_sku' => 'SKU-STORE-B-FAIL',
                'store_ids' => [$this->storeB->id],
            ]
        );
    }

    public function test_scenario_e_multi_store_creation_rolls_back_entirely_if_any_store_participation_is_missing(): void
    {
        $vendorPartial = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $this->vendor->vendor_plan_id,
            'name' => 'Partial Vendor',
            'platform_slug' => 'partial-'.uniqid(),
            'legal_name' => 'Partial Corp',
            'email' => 'partial@vendor.com',
            'operational_status' => VendorOperationalStatus::Active,
        ]);

        // Participates only in Store A
        VendorStoreParticipation::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $vendorPartial->id,
            'store_id' => $this->storeA->id,
            'is_enabled' => true,
        ]);

        $service = app(VendorListingCreationServiceInterface::class);

        $thrown = false;
        try {
            // Request both Store A (valid) and Store B (invalid)
            $service->createListing(
                $this->tenant->id,
                $vendorPartial->id,
                [
                    'product_id' => $this->product->id,
                    'product_variant_id' => null,
                    'vendor_sku' => 'SKU-ATOMIC-ROLLBACK',
                    'store_ids' => [$this->storeA->id, $this->storeB->id],
                ]
            );
        } catch (VendorStoreParticipationException) {
            $thrown = true;
        }

        $this->assertTrue($thrown, 'VendorStoreParticipationException must be thrown.');

        // ATOMIC ROLLBACK PROOF: No listing, and no availability for Store A either!
        $this->assertDatabaseMissing('vendor_listings', [
            'vendor_sku' => 'SKU-ATOMIC-ROLLBACK',
        ]);
        $this->assertDatabaseMissing('vendor_listing_store_availabilities', [
            'store_id' => $this->storeA->id,
            'tenant_id' => $this->tenant->id,
            'vendor_listing_id' => VendorListing::where('vendor_sku', 'SKU-ATOMIC-ROLLBACK')->value('id') ?? 0,
        ]);
    }
}
