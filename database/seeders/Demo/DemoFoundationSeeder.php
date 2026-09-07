<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Markets\Models\Market;
use App\Core\Markets\Models\MarketCurrency;
use App\Core\Markets\Models\StoreMarket;
use App\Core\Stores\Models\Store;
use App\Core\Stores\Models\StoreUser;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\Phase22PermissionSeeder;
use Illuminate\Database\Seeder;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\Warehouse;
use Modules\POS\Models\PosRegister;
use Modules\Shipping\Models\PickupLocation;

/**
 * Pre-Production Readiness — Demo Dataset foundation: Tenant, users
 * (one per representative role), Store, Website + POS Channels, Market,
 * Warehouse/InventorySource, PickupLocation, POS Register. Every row is
 * created through the same models/relations the real app uses — no
 * invariant is bypassed.
 */
class DemoFoundationSeeder extends Seeder
{
    public const string TENANT_SLUG = 'demo';

    public function run(): void
    {
        $plan = PlatformSaasPlan::firstOrCreate(
            ['code' => 'demo-plan'],
            ['name' => 'Demo Plan', 'status' => 'active', 'limits' => ['max_stores' => 5]]
        );

        $tenant = Tenant::firstOrCreate(
            ['slug' => self::TENANT_SLUG],
            ['name' => 'Demo Store Inc.', 'status' => 'active']
        );

        app(TenantLicenseServiceInterface::class)->assignLicense($tenant->id, $plan->id);

        $store = Store::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'demo-flagship'],
            ['name' => 'Demo Flagship Store', 'status' => 'active', 'active_theme' => 'default']
        );

        $market = Market::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'DEMO_US'],
            ['name' => 'United States', 'default_currency_code' => 'USD', 'default_locale_code' => 'en', 'is_active' => true]
        );
        MarketCurrency::firstOrCreate(['market_id' => $market->id, 'currency_code' => 'USD'], ['is_default' => true]);
        StoreMarket::firstOrCreate(['store_id' => $store->id, 'market_id' => $market->id], ['is_active' => true, 'is_default' => true]);

        $webChannel = Channel::firstOrCreate(['handle' => 'demo-web'], ['type' => 'website', 'name' => 'Website', 'is_active' => true]);
        StoreChannel::firstOrCreate(['store_id' => $store->id, 'channel_id' => $webChannel->id]);

        $posChannel = Channel::firstOrCreate(['handle' => 'demo-pos'], ['type' => 'pos', 'name' => 'Point of Sale', 'is_active' => true]);
        StoreChannel::firstOrCreate(['store_id' => $store->id, 'channel_id' => $posChannel->id]);

        $warehouse = Warehouse::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'DEMO_WH'],
            ['name' => 'Demo Warehouse', 'country_code' => 'US', 'status' => 'active']
        );
        $source = InventorySource::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'DEMO_SRC'],
            ['warehouse_id' => $warehouse->id, 'name' => 'Demo Main Source', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]
        );

        PickupLocation::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'DEMO_PICKUP'],
            ['name' => 'Demo Store Pickup Counter', 'inventory_source_id' => $source->id, 'warehouse_id' => $warehouse->id, 'fee_amount' => 0, 'currency' => 'USD', 'status' => 'active', 'created_at' => now()]
        );

        $storeMarket = StoreMarket::where('store_id', $store->id)->where('market_id', $market->id)->firstOrFail();
        $register = PosRegister::firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'DEMO_REG1'],
            ['store_market_id' => $storeMarket->id, 'channel_id' => $posChannel->id, 'inventory_source_id' => $source->id, 'name' => 'Front Counter Register', 'status' => 'active']
        );

        // Owner Delta: representative role accounts — predictable, local-
        // demo-only credentials, real bcrypt hashing via User::create()'s
        // 'hashed' cast (never a plaintext-equivalent shortcut).
        $tenantOwner = User::firstOrCreate(
            ['email' => 'owner@demo.hyperstore.test'],
            ['name' => 'Demo Store Owner', 'password' => 'password', 'status' => 'active', 'is_super_admin' => false]
        );
        StoreUser::firstOrCreate(['store_id' => $store->id, 'user_id' => $tenantOwner->id], ['role' => 'owner', 'is_active' => true]);

        $cashier = User::firstOrCreate(
            ['email' => 'cashier@demo.hyperstore.test'],
            ['name' => 'Demo Cashier', 'password' => 'password', 'status' => 'active', 'is_super_admin' => false]
        );
        StoreUser::firstOrCreate(['store_id' => $store->id, 'user_id' => $cashier->id], ['role' => 'cashier', 'is_active' => true]);
        $cashier->givePermissionTo(Phase22PermissionSeeder::PERMISSIONS);

        $customer = User::firstOrCreate(
            ['email' => 'customer@demo.hyperstore.test'],
            ['name' => 'Demo Customer', 'password' => 'password', 'status' => 'active', 'is_super_admin' => false]
        );

        User::firstOrCreate(
            ['email' => 'vendor@demo.hyperstore.test'],
            ['name' => 'Demo Vendor Staff', 'password' => 'password', 'status' => 'active', 'is_super_admin' => false]
        );

        User::firstOrCreate(
            ['email' => 'b2b-buyer@demo.hyperstore.test'],
            ['name' => 'Demo B2B Buyer', 'password' => 'password', 'status' => 'active', 'is_super_admin' => false]
        );

        User::firstOrCreate(
            ['email' => 'b2b-approver@demo.hyperstore.test'],
            ['name' => 'Demo B2B Approver', 'password' => 'password', 'status' => 'active', 'is_super_admin' => false]
        );

        $this->command?->info('Demo foundation seeded: Tenant ['.$tenant->slug.'], Store ['.$store->slug.'], Register ['.$register->code.'].');
    }
}
