<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\ShippingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneAssignment;
use Tests\TestCase;

class ShippingApiAndRbacTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ShippingPermissionSeeder::class);

        $this->tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        $this->tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);

        $this->userA = User::create([
            'name' => 'User A',
            'email' => 'user.a@hyperstore.ch',
            'password' => bcrypt('secret123'),
            'tenant_id' => $this->tenantA->id,
        ]);
    }

    public function test_store_market_cross_tenant_assignment_is_rejected_by_api(): void
    {
        Sanctum::actingAs($this->userA);
        $this->userA->givePermissionTo('shipping.zones.manage');

        // Zone in Tenant A
        $zoneA = ShippingZone::create(['tenant_id' => $this->tenantA->id, 'code' => 'ZONE_A_ASSIGN', 'name' => 'Zone A', 'status' => 'active']);

        // Store, Market in Tenant B
        $storeB = Store::create(['tenant_id' => $this->tenantB->id, 'code' => 'STORE_B', 'name' => 'Store B', 'slug' => 'store-b', 'status' => 'active']);
        $marketB = Market::create(['tenant_id' => $this->tenantB->id, 'code' => 'MKT_B', 'name' => 'Market B', 'default_currency_code' => 'EUR', 'default_locale_code' => 'en', 'is_active' => true]);

        // Attempt assigning Tenant B store to Tenant A zone -> 404
        $resStore = $this->postJson("/api/v1/shipping/zones/{$zoneA->id}/assignments", [
            'store_id' => $storeB->id,
        ], ['X-Tenant-ID' => (string) $this->tenantA->id]);
        $resStore->assertStatus(404);

        // Attempt assigning Tenant B market to Tenant A zone -> 404
        $resMarket = $this->postJson("/api/v1/shipping/zones/{$zoneA->id}/assignments", [
            'market_id' => $marketB->id,
        ], ['X-Tenant-ID' => (string) $this->tenantA->id]);
        $resMarket->assertStatus(404);
    }

    public function test_model_domain_guard_rejects_cross_tenant_store_assignment(): void
    {
        $zoneA = ShippingZone::create(['tenant_id' => $this->tenantA->id, 'code' => 'ZONE_A_GUARD', 'name' => 'Zone A Guard', 'status' => 'active']);
        $storeB = Store::create(['tenant_id' => $this->tenantB->id, 'code' => 'STORE_B_GUARD', 'name' => 'Store B Guard', 'slug' => 'store-b-g', 'status' => 'active']);

        $this->expectException(InvalidArgumentException::class);
        ShippingZoneAssignment::create([
            'shipping_zone_id' => $zoneA->id,
            'store_id' => $storeB->id,
        ]);
    }

    public function test_model_domain_guard_rejects_cross_tenant_market_assignment(): void
    {
        $zoneA = ShippingZone::create(['tenant_id' => $this->tenantA->id, 'code' => 'ZONE_A_MKT_G', 'name' => 'Zone A Mkt Guard', 'status' => 'active']);
        $marketB = Market::create(['tenant_id' => $this->tenantB->id, 'code' => 'MKT_B_G', 'name' => 'Market B Guard', 'default_currency_code' => 'EUR', 'default_locale_code' => 'en', 'is_active' => true]);

        $this->expectException(InvalidArgumentException::class);
        ShippingZoneAssignment::create([
            'shipping_zone_id' => $zoneA->id,
            'market_id' => $marketB->id,
        ]);
    }

    public function test_valid_same_tenant_assignment_succeeds(): void
    {
        Sanctum::actingAs($this->userA);
        $this->userA->givePermissionTo('shipping.zones.manage');

        $zoneA = ShippingZone::create(['tenant_id' => $this->tenantA->id, 'code' => 'ZONE_A_VALID', 'name' => 'Zone A Valid', 'status' => 'active']);
        $storeA = Store::create(['tenant_id' => $this->tenantA->id, 'code' => 'STORE_A', 'name' => 'Store A', 'slug' => 'store-a', 'status' => 'active']);

        $res = $this->postJson("/api/v1/shipping/zones/{$zoneA->id}/assignments", [
            'store_id' => $storeA->id,
        ], ['X-Tenant-ID' => (string) $this->tenantA->id]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('shipping_zone_assignments', [
            'shipping_zone_id' => $zoneA->id,
            'store_id' => $storeA->id,
        ]);
    }

    public function test_rbac_denial_on_all_resource_read_endpoints(): void
    {
        Sanctum::actingAs($this->userA);
        // User A has NO permissions

        $endpoints = [
            '/api/v1/shipping/zones',
            '/api/v1/shipping/methods',
            '/api/v1/shipping/carriers',
            '/api/v1/shipping/classes',
            '/api/v1/shipping/package-types',
            '/api/v1/shipping/pickup-locations',
            '/api/v1/shipping/restrictions',
            '/api/v1/shipping/source-method-mappings',
            '/api/v1/fulfillment/source-configurations',
            '/api/v1/fulfillment/strategies',
        ];

        foreach ($endpoints as $ep) {
            $res = $this->getJson($ep, ['X-Tenant-ID' => (string) $this->tenantA->id]);
            $res->assertStatus(403);
        }
    }
}
