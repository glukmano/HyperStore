<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Core\Payables\Enums\PayableAvailabilityStatus;
use App\Core\Payables\Enums\PayableEntryType;
use App\Core\Routing\Exceptions\HostnameAlreadyClaimedException;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductVariant;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorCommissionRule;
use Modules\Marketplace\Models\VendorDomain;
use Modules\Marketplace\Models\VendorListing;
use Modules\Marketplace\Models\VendorListingStoreAvailability;
use Modules\Marketplace\Models\VendorPayableEntry;
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

    private Product $productA;

    private Product $productB;

    private ProductVariant $variantA;

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

        $this->productA = Product::create([
            'tenant_id' => $this->tenantA->id,
            'product_type' => 'simple',
            'sku' => 'SKU-PA-'.uniqid(),
            'status' => 'active',
        ]);

        $this->productB = Product::create([
            'tenant_id' => $this->tenantB->id,
            'product_type' => 'simple',
            'sku' => 'SKU-PB-'.uniqid(),
            'status' => 'active',
        ]);

        $this->variantA = ProductVariant::create([
            'tenant_id' => $this->tenantA->id,
            'product_id' => $this->productA->id,
            'sku' => 'VAR-A-'.uniqid(),
            'combination_hash' => md5('var-a-'.uniqid()),
            'status' => 'active',
        ]);
    }

    public function test_postgres_trigger_rejects_cross_tenant_product_vendor_listing(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cross-tenant catalog reference prohibited');

        // VendorListing belonging to Tenant A attempts to reference Product B belonging to Tenant B
        VendorListing::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'product_id' => $this->productB->id, // Tenant B product!
            'product_variant_id' => null,
            'vendor_sku' => 'V-SKU-X1',
        ]);
    }

    public function test_postgres_trigger_rejects_cross_tenant_variant_vendor_listing(): void
    {
        // Variant belonging to Tenant B
        $variantB = ProductVariant::create([
            'tenant_id' => $this->tenantB->id,
            'product_id' => $this->productB->id,
            'sku' => 'VAR-B-'.uniqid(),
            'combination_hash' => md5('var-b-'.uniqid()),
            'status' => 'active',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cross-tenant catalog variant reference prohibited');

        VendorListing::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'product_id' => $this->productA->id,
            'product_variant_id' => $variantB->id, // Tenant B variant!
            'vendor_sku' => 'V-SKU-X2',
        ]);
    }

    public function test_postgres_trigger_rejects_variant_product_mismatch(): void
    {
        // Second product belonging to Tenant A
        $secondProductA = Product::create([
            'tenant_id' => $this->tenantA->id,
            'product_type' => 'simple',
            'sku' => 'SKU-PA2-'.uniqid(),
            'status' => 'active',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Catalog variant mismatch prohibited');

        // References $secondProductA with $variantA which belongs to $productA
        VendorListing::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'product_id' => $secondProductA->id,
            'product_variant_id' => $this->variantA->id,
            'vendor_sku' => 'V-SKU-X3',
        ]);
    }

    public function test_postgres_trigger_rejects_cross_tenant_default_store_on_vendor(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cross-tenant default store prohibited');

        Vendor::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_plan_id' => $this->planA->id,
            'default_store_id' => $this->storeB->id, // Tenant B store!
            'name' => 'Cross Store Vendor',
            'platform_slug' => 'cross-store-'.uniqid(),
            'legal_name' => 'Cross LLC',
            'email' => 'cross@store.com',
        ]);
    }

    public function test_postgres_trigger_rejects_cross_tenant_store_participation(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cross-tenant store relationship is prohibited');

        VendorStoreParticipation::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'store_id' => $this->storeB->id,
            'is_enabled' => true,
        ]);
    }

    public function test_postgres_trigger_rejects_cross_tenant_listing_store_availability(): void
    {
        $listing = VendorListing::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'product_id' => $this->productA->id,
            'product_variant_id' => null,
            'vendor_sku' => 'SKU-AVAIL-1',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Cross-tenant store relationship is prohibited');

        VendorListingStoreAvailability::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_listing_id' => $listing->id,
            'store_id' => $this->storeB->id,
            'is_enabled' => true,
        ]);
    }

    public function test_postgres_partial_unique_index_rejects_second_active_owner(): void
    {
        $user1 = User::factory()->create(['email' => 'owner_uniq1_'.uniqid().'@example.com']);
        $user2 = User::factory()->create(['email' => 'owner_uniq2_'.uniqid().'@example.com']);

        VendorUser::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'user_id' => $user1->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->expectException(QueryException::class);
        VendorUser::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'user_id' => $user2->id,
            'role' => 'owner',
            'is_active' => true,
        ]);
    }

    public function test_postgres_rejects_duplicate_global_platform_slug(): void
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
        Vendor::create([
            'tenant_id' => $this->tenantB->id,
            'vendor_plan_id' => $this->planB->id,
            'name' => 'Second Slug Owner',
            'platform_slug' => $slug,
            'legal_name' => 'Second LLC',
            'email' => 'second@slug.com',
        ]);
    }

    public function test_postgres_rejects_duplicate_custom_domain(): void
    {
        $domain = 'custom-'.uniqid().'.com';

        VendorDomain::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'domain' => $domain,
            'verification_token' => 'tok_1',
        ]);

        // Phase-18 Owner Delta §5: the global hostname_claims registry now
        // rejects the collision before the row-level UNIQUE(domain) on
        // vendor_domains itself is even reached, via
        // HostnameAlreadyClaimedException — the underlying per-table
        // UNIQUE constraint this test originally proved still exists as a
        // second line of defense, it's just no longer the first one hit.
        $this->expectException(HostnameAlreadyClaimedException::class);
        VendorDomain::create([
            'tenant_id' => $this->tenantB->id,
            'vendor_id' => $this->vendorA->id,
            'domain' => $domain,
            'verification_token' => 'tok_2',
        ]);
    }

    public function test_postgres_partial_unique_index_rejects_duplicate_nullable_product_listing(): void
    {
        VendorListing::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'product_id' => $this->productA->id,
            'product_variant_id' => null,
            'vendor_sku' => 'SKU-L1',
        ]);

        $this->expectException(QueryException::class);
        VendorListing::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'product_id' => $this->productA->id,
            'product_variant_id' => null,
            'vendor_sku' => 'SKU-L2',
        ]);
    }

    public function test_postgres_partial_unique_index_rejects_overlapping_commission_rules(): void
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
        VendorCommissionRule::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'category_id' => null,
            'rate_basis_points' => 1500,
            'currency' => 'EUR',
            'is_active' => true,
        ]);
    }

    public function test_postgres_trigger_strictly_prohibits_delete_on_vendor_payable_entries(): void
    {
        $entry = VendorPayableEntry::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'entry_type' => PayableEntryType::Earning,
            'source_type' => 'order_item',
            'source_uuid' => 'oi_nodelete_'.uniqid(),
            'currency' => 'EUR',
            'amount_minor' => 5000,
            'commission_amount_minor' => 500,
            'net_amount_minor' => 4500,
            'availability_status' => PayableAvailabilityStatus::Pending,
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Deleting rows from vendor_payable_entries is strictly prohibited');

        // Direct raw SQL delete must be rejected by PostgreSQL trigger
        DB::table('vendor_payable_entries')->where('id', $entry->id)->delete();
    }

    public function test_postgres_trigger_strictly_prohibits_update_of_economic_fields_on_payable_entries(): void
    {
        $entry = VendorPayableEntry::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'entry_type' => PayableEntryType::Earning,
            'source_type' => 'order_item',
            'source_uuid' => 'oi_noupdate_'.uniqid(),
            'currency' => 'EUR',
            'amount_minor' => 5000,
            'commission_amount_minor' => 500,
            'net_amount_minor' => 4500,
            'availability_status' => PayableAvailabilityStatus::Pending,
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Economic fields of vendor_payable_entries are immutable');

        // Direct raw SQL update of amount_minor must be rejected by PostgreSQL trigger
        DB::table('vendor_payable_entries')->where('id', $entry->id)->update([
            'amount_minor' => 9999,
        ]);
    }

    public function test_postgres_permits_controlled_availability_field_updates_on_payable_entries(): void
    {
        $entry = VendorPayableEntry::create([
            'tenant_id' => $this->tenantA->id,
            'vendor_id' => $this->vendorA->id,
            'entry_type' => PayableEntryType::Earning,
            'source_type' => 'order_item',
            'source_uuid' => 'oi_legal_'.uniqid(),
            'currency' => 'EUR',
            'amount_minor' => 5000,
            'commission_amount_minor' => 500,
            'net_amount_minor' => 4500,
            'availability_status' => PayableAvailabilityStatus::Pending,
        ]);

        // Updating availability_status, available_at, or held_reason is permitted
        DB::table('vendor_payable_entries')->where('id', $entry->id)->update([
            'availability_status' => 'available',
            'available_at' => now(),
            'held_reason' => null,
            'updated_at' => now(),
        ]);

        $this->assertSame('available', DB::table('vendor_payable_entries')->where('id', $entry->id)->value('availability_status'));
    }
}
