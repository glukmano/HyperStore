<?php

declare(strict_types=1);

namespace Tests\Feature\Routing;

use App\Core\Markets\Models\Market;
use App\Core\Markets\Models\MarketDomain;
use App\Core\Markets\Models\StoreMarket;
use App\Core\ReferenceData\Models\Currency;
use App\Core\ReferenceData\Models\Language;
use App\Core\Routing\DomainAddressingService;
use App\Core\Routing\Exceptions\HostnameAlreadyClaimedException;
use App\Core\Stores\Models\Store;
use App\Core\Stores\Models\StoreDomain;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorDomain;
use Modules\Marketplace\Models\VendorPlan;
use Tests\TestCase;

/**
 * Phase-18 Owner Delta §5: a real PostgreSQL test proving the global
 * hostname_claims UNIQUE constraint — not each table's own independent
 * UNIQUE(domain) — is what actually prevents Store/Market/Vendor domain
 * collision across the three separate tables.
 */
class HostnameClaimPostgreSqlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'hyperstore',
            'database.connections.pgsql.username' => 'lukman',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => 5432,
        ]);
        DB::purge('pgsql');
        DB::reconnect('pgsql');

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL engine required for hostname-claim integrity tests.');
        }
    }

    public function test_the_same_hostname_cannot_be_claimed_by_a_store_and_then_a_market(): void
    {
        $tenant = Tenant::create(['slug' => 'hc-tenant-'.uniqid(), 'name' => 'HC Tenant', 'status' => 'active']);
        Currency::firstOrCreate(['code' => 'CHF'], ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_default' => false, 'is_active' => true]);
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);

        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'HC Store', 'slug' => 'hc-store-'.uniqid(), 'status' => 'active']);
        $market = Market::create(['tenant_id' => $tenant->id, 'name' => 'HC Market', 'code' => 'HC-'.uniqid(), 'is_active' => true, 'default_currency_code' => 'CHF', 'default_locale_code' => 'en', 'timezone' => 'UTC']);
        $storeMarket = StoreMarket::create(['store_id' => $store->id, 'market_id' => $market->id, 'is_active' => true, 'is_default' => true]);

        $host = 'collide-'.uniqid().'.test';

        StoreDomain::create(['store_id' => $store->id, 'domain' => $host, 'type' => 'custom', 'is_verified' => false, 'canonical' => false]);

        $this->expectException(HostnameAlreadyClaimedException::class);

        MarketDomain::create(['store_market_id' => $storeMarket->id, 'domain' => $host, 'is_verified' => false, 'canonical' => false]);
    }

    public function test_the_same_hostname_cannot_be_claimed_by_a_vendor_and_then_a_store(): void
    {
        $tenant = Tenant::create(['slug' => 'hc-tenant2-'.uniqid(), 'name' => 'HC Tenant 2', 'status' => 'active']);
        $plan = VendorPlan::create(['tenant_id' => $tenant->id, 'name' => 'Basic', 'code' => 'basic']);
        $vendor = Vendor::create([
            'tenant_id' => $tenant->id, 'vendor_plan_id' => $plan->id, 'name' => 'HC Vendor',
            'platform_slug' => 'hc-vendor-'.uniqid(), 'legal_name' => 'HC Vendor Corp', 'email' => 'hcvendor-'.uniqid().'@test.com', 'payout_currency' => 'USD',
        ]);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'HC Store 2', 'slug' => 'hc-store2-'.uniqid(), 'status' => 'active']);

        $host = 'collide2-'.uniqid().'.test';

        VendorDomain::create(['tenant_id' => $tenant->id, 'vendor_id' => $vendor->id, 'domain' => $host, 'verification_token' => bin2hex(random_bytes(8)), 'status' => 'requested']);

        $this->expectException(HostnameAlreadyClaimedException::class);

        StoreDomain::create(['store_id' => $store->id, 'domain' => $host, 'type' => 'custom', 'is_verified' => false, 'canonical' => false]);
    }

    public function test_a_market_domain_resolves_the_unique_store_and_market_pair_when_one_market_has_multiple_stores(): void
    {
        $tenant = Tenant::create(['slug' => 'hc-tenant3-'.uniqid(), 'name' => 'HC Tenant 3', 'status' => 'active']);
        Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_default' => false, 'is_active' => true]);
        Language::firstOrCreate(['code' => 'de'], ['name' => 'German', 'native_name' => 'Deutsch', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);

        $storeA = Store::create(['tenant_id' => $tenant->id, 'name' => 'Store A', 'slug' => 'store-a-'.uniqid(), 'status' => 'active']);
        $storeB = Store::create(['tenant_id' => $tenant->id, 'name' => 'Store B', 'slug' => 'store-b-'.uniqid(), 'status' => 'active']);
        $sharedMarket = Market::create(['tenant_id' => $tenant->id, 'name' => 'Shared Market', 'code' => 'SHARED-'.uniqid(), 'is_active' => true, 'default_currency_code' => 'EUR', 'default_locale_code' => 'de', 'timezone' => 'UTC']);

        $storeMarketA = StoreMarket::create(['store_id' => $storeA->id, 'market_id' => $sharedMarket->id, 'is_active' => true, 'is_default' => true]);
        StoreMarket::create(['store_id' => $storeB->id, 'market_id' => $sharedMarket->id, 'is_active' => true, 'is_default' => true]);

        $host = 'storea-'.uniqid().'.test';
        MarketDomain::create(['store_market_id' => $storeMarketA->id, 'domain' => $host, 'is_verified' => true, 'canonical' => true]);

        $resolved = app(DomainAddressingService::class)->resolveHostContext($host);

        $this->assertNotNull($resolved->store);
        $this->assertSame($storeA->id, $resolved->store->id);
        $this->assertNotNull($resolved->market);
        $this->assertSame($sharedMarket->id, $resolved->market->id);
    }

    public function test_an_unverified_market_domain_never_resolves(): void
    {
        $tenant = Tenant::create(['slug' => 'hc-tenant4-'.uniqid(), 'name' => 'HC Tenant 4', 'status' => 'active']);
        Currency::firstOrCreate(['code' => 'GBP'], ['name' => 'Pound', 'symbol' => '£', 'decimals' => 2, 'is_default' => false, 'is_active' => true]);
        Language::firstOrCreate(['code' => 'en-GB'], ['name' => 'English (UK)', 'native_name' => 'English (UK)', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);

        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Unverified Store', 'slug' => 'unv-store-'.uniqid(), 'status' => 'active']);
        $market = Market::create(['tenant_id' => $tenant->id, 'name' => 'UK Market', 'code' => 'UK-'.uniqid(), 'is_active' => true, 'default_currency_code' => 'GBP', 'default_locale_code' => 'en-GB', 'timezone' => 'Europe/London']);
        $storeMarket = StoreMarket::create(['store_id' => $store->id, 'market_id' => $market->id, 'is_active' => true, 'is_default' => true]);

        $host = 'unverified-'.uniqid().'.test';
        MarketDomain::create(['store_market_id' => $storeMarket->id, 'domain' => $host, 'is_verified' => false, 'canonical' => false]);

        $resolved = app(DomainAddressingService::class)->resolveHostContext($host);

        $this->assertNull($resolved->store);
        $this->assertNull($resolved->market);
    }

    public function test_new_market_domains_default_to_unverified(): void
    {
        $tenant = Tenant::create(['slug' => 'hc-tenant5-'.uniqid(), 'name' => 'HC Tenant 5', 'status' => 'active']);
        Currency::firstOrCreate(['code' => 'SEK'], ['name' => 'Swedish Krona', 'symbol' => 'kr', 'decimals' => 2, 'is_default' => false, 'is_active' => true]);
        Language::firstOrCreate(['code' => 'sv'], ['name' => 'Swedish', 'native_name' => 'Svenska', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);

        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'SE Store', 'slug' => 'se-store-'.uniqid(), 'status' => 'active']);
        $market = Market::create(['tenant_id' => $tenant->id, 'name' => 'Sweden', 'code' => 'SE-'.uniqid(), 'is_active' => true, 'default_currency_code' => 'SEK', 'default_locale_code' => 'sv', 'timezone' => 'Europe/Stockholm']);
        $storeMarket = StoreMarket::create(['store_id' => $store->id, 'market_id' => $market->id, 'is_active' => true, 'is_default' => true]);

        $domain = MarketDomain::create(['store_market_id' => $storeMarket->id, 'domain' => 'se-'.uniqid().'.test']);
        $domain->refresh();

        $this->assertFalse($domain->is_verified);
    }
}
