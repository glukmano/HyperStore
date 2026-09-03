<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Product;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorCommissionRule;
use Modules\Marketplace\Models\VendorDomain;
use Modules\Marketplace\Models\VendorListing;
use Modules\Marketplace\Models\VendorListingStoreAvailability;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Models\VendorStoreParticipation;
use Modules\Marketplace\Models\VendorUser;
use Tests\TestCase;

class PostgreSqlMarketplaceEngineIntegrityTest extends TestCase
{
    private Tenant $tenantA;

    private Tenant $tenantB;

    private Store $storeA;

    private Store $storeB;

    private VendorPlan $planA;

    private VendorPlan $planB;

    private Vendor $vendorA;

    private User $userA;

    private User $userB;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'hyperstore',
            'database.connections.pgsql.username' => 'lukman',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => 5432,
            'database.connections.pgsql.timezone' => 'UTC',
        ]);
        DB::purge('pgsql');

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSqlMarketplaceEngineIntegrityTest requires PostgreSQL engine.');
        }

        $this->seed(ReferenceDataSeeder::class);

        $this->tenantA = Tenant::create(['name' => 'Engine Tenant A', 'slug' => 'eng-a-'.uniqid(), 'status' => 'active']);
        $this->tenantB = Tenant::create(['name' => 'Engine Tenant B', 'slug' => 'eng-b-'.uniqid(), 'status' => 'active']);

        $this->storeA = Store::create(['tenant_id' => $this->tenantA->id, 'name' => 'Store A', 'slug' => 'store-a-'.uniqid(), 'status' => 'active']);
        $this->storeB = Store::create(['tenant_id' => $this->tenantB->id, 'name' => 'Store B', 'slug' => 'store-b-'.uniqid(), 'status' => 'active']);

        $this->planA = VendorPlan::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Plan A',
            'code' => 'plan-a-'.uniqid(),
            'commission_rate_bps' => 1000,
            'fixed_fee_minor' => 100,
        ]);
        $this->planB = VendorPlan::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Plan B',
            'code' => 'plan-b-'.uniqid(),
            'commission_rate_bps' => 1200,
            'fixed_fee_minor' => 50,
        ]);

        $this->vendorA = Vendor::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_plan_id' => $this->planA->id,
            'name' => 'Vendor A',
            'platform_slug' => 'slug-a-'.uniqid(),
            'legal_name' => 'Vendor A Corp',
            'email' => 'vendorA@test.com',
        ]);

        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();

        $this->product = Product::create([
            'tenant_id' => $this->tenantA->id,
            'product_type' => 'simple',
            'sku' => 'CANON-SKU-'.uniqid(),
            'status' => 'active',
        ]);
    }

    public function test_postgres_rejects_duplicate_global_platform_slug_across_tenants(): void
    {
        $slug = 'global-slug-'.uniqid();

        Vendor::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_plan_id' => $this->planA->id,
            'name' => 'First Slug Owner',
            'platform_slug' => $slug,
            'legal_name' => 'First LLC',
            'email' => 'first@slug.com',
        ]);

        $this->expectException(QueryException::class);
        // Tenant B attempts same platform slug
        Vendor::create([
            'tenant_id' => $this->tenantB->id,
            'vendor_plan_id' => $this->planB->id,
            'name' => 'Second Slug Owner Across Tenants',
            'platform_slug' => $slug,
            'legal_name' => 'Second LLC',
            'email' => 'second@slug.com',
        ]);
    }

    public function test_postgres_rejects_duplicate_normalized_custom_domain(): void
    {
        $domain = 'vendor-shop-'.uniqid().'.com';

        VendorDomain::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'domain' => $domain,
            'verification_token' => 'tok_1',
        ]);

        $this->expectException(QueryException::class);
        // Attempt duplicate domain
        VendorDomain::create([
            'tenant_id' => $this->tenantB->id,
            'vendor_id' => $this->vendorA->id,
            'domain' => $domain,
            'verification_token' => 'tok_2',
        ]);
    }

    public function test_postgres_partial_unique_index_rejects_second_active_owner(): void
    {
        // First active owner
        VendorUser::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'user_id' => $this->userA->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);
        // Second active owner for same vendor must be rejected by PostgreSQL partial index uq_vendor_single_owner
        VendorUser::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'user_id' => $this->userB->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
    }

    public function test_postgres_partial_unique_index_rejects_duplicate_canonical_product_listing(): void
    {
        VendorListing::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'product_id' => $this->product->id,
            'product_variant_id' => null,
            'vendor_sku' => 'SKU-C1',
        ]);

        $this->expectException(QueryException::class);
        // Duplicate listing for same product with NULL variant must be rejected by uq_vendor_listings_product
        VendorListing::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'product_id' => $this->product->id,
            'product_variant_id' => null,
            'vendor_sku' => 'SKU-C2',
        ]);
    }

    public function test_postgres_partial_unique_index_rejects_overlapping_vendor_global_commission_rules(): void
    {
        VendorCommissionRule::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'category_id' => null,
            'rate_basis_points' => 1000,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);
        // Second active global rule for same vendor and currency rejected by uq_commission_vendor_global
        VendorCommissionRule::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'category_id' => null,
            'rate_basis_points' => 1500,
            'currency' => 'EUR',
            'is_active' => true,
        ]);
    }

    public function test_postgres_trigger_rejects_cross_tenant_store_participation(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cross-tenant store relationship is prohibited');

        // Vendor belonging to Tenant A attempts to participate in Store B belonging to Tenant B
        VendorStoreParticipation::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'store_id' => $this->storeB->id, // Belongs to Tenant B!
            'is_enabled' => true,
        ]);
    }

    public function test_postgres_trigger_rejects_cross_tenant_listing_store_availability(): void
    {
        $listing = VendorListing::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'product_id' => $this->product->id,
            'product_variant_id' => null,
            'vendor_sku' => 'SKU-AVAIL-1',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cross-tenant store relationship is prohibited');

        // Listing belonging to Tenant A attempts availability in Store B belonging to Tenant B
        VendorListingStoreAvailability::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_listing_id' => $listing->id,
            'store_id' => $this->storeB->id, // Belongs to Tenant B!
            'is_enabled' => true,
        ]);
    }
}
