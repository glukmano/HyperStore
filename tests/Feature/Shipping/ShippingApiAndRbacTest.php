<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\ShippingPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\ShippingZone;
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

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $res = $this->getJson('/api/v1/shipping/zones', ['X-Tenant-ID' => (string) $this->tenantA->id]);
        $res->assertStatus(401);
    }

    public function test_authenticated_user_without_permission_returns_403_forbidden(): void
    {
        Sanctum::actingAs($this->userA);

        $res = $this->getJson('/api/v1/shipping/zones', ['X-Tenant-ID' => (string) $this->tenantA->id]);
        $res->assertStatus(403);
    }

    public function test_authenticated_user_with_permission_can_manage_zones_and_methods(): void
    {
        Sanctum::actingAs($this->userA);
        $this->userA->givePermissionTo('shipping.zones.manage');
        $this->userA->givePermissionTo('shipping.zones.view');
        $this->userA->givePermissionTo('shipping.methods.manage');
        $this->userA->givePermissionTo('shipping.methods.view');

        // Create Zone
        $createZone = $this->postJson('/api/v1/shipping/zones', [
            'code' => 'SWISS_ZONE',
            'name' => 'Swiss National Zone',
            'priority' => 10,
        ], ['X-Tenant-ID' => (string) $this->tenantA->id]);
        $createZone->assertStatus(201);
        $zoneId = $createZone->json('id');

        // Create Method
        $createMethod = $this->postJson('/api/v1/shipping/methods', [
            'code' => 'SWISS_EXP',
            'name' => 'Swiss Express',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 1500,
        ], ['X-Tenant-ID' => (string) $this->tenantA->id]);
        $createMethod->assertStatus(201);
        $methodId = $createMethod->json('id');

        // Assign Method to Zone
        $assignRes = $this->postJson("/api/v1/shipping/methods/{$methodId}/zones", [
            'shipping_zone_id' => $zoneId,
        ], ['X-Tenant-ID' => (string) $this->tenantA->id]);
        $assignRes->assertStatus(201);

        // Delete Zone
        $delRes = $this->deleteJson("/api/v1/shipping/zones/{$zoneId}", [], ['X-Tenant-ID' => (string) $this->tenantA->id]);
        $delRes->assertStatus(200);
    }

    public function test_cross_tenant_resource_access_returns_404(): void
    {
        Sanctum::actingAs($this->userA);
        $this->userA->givePermissionTo('shipping.zones.view');

        // Zone belonging to Tenant B
        $zoneB = ShippingZone::create([
            'tenant_id' => $this->tenantB->id,
            'code' => 'ZONE_B',
            'name' => 'Zone B',
            'status' => 'active',
        ]);

        // User A querying Zone B with Tenant A context -> 404
        $res = $this->getJson("/api/v1/shipping/zones/{$zoneB->id}", ['X-Tenant-ID' => (string) $this->tenantA->id]);
        $res->assertStatus(404);
    }

    public function test_carrier_credentials_endpoint_stores_encrypted_and_never_returns_secret(): void
    {
        Sanctum::actingAs($this->userA);
        $this->userA->givePermissionTo('shipping.credentials.manage');

        $carrier = Carrier::create([
            'tenant_id' => $this->tenantA->id,
            'code' => 'SWISS_POST',
            'name' => 'Swiss Post',
            'provider_code' => 'manual',
            'status' => 'active',
        ]);

        $res = $this->postJson("/api/v1/shipping/carriers/{$carrier->id}/credentials", [
            'environment' => 'production',
            'credentials' => [
                'api_token' => 'SECRET_TOKEN_999',
            ],
        ], ['X-Tenant-ID' => (string) $this->tenantA->id]);

        $res->assertStatus(200);
        $res->assertJsonFragment(['success' => true]);
        // Secret must not be in response
        $this->assertStringNotContainsString('SECRET_TOKEN_999', $res->getContent());
    }

    public function test_shipping_classes_and_package_types_crud(): void
    {
        Sanctum::actingAs($this->userA);
        $this->userA->givePermissionTo('shipping.manage');

        // Shipping Class
        $classRes = $this->postJson('/api/v1/shipping/classes', [
            'code' => 'HAZMAT',
            'name' => 'Hazardous Materials',
        ], ['X-Tenant-ID' => (string) $this->tenantA->id]);
        $classRes->assertStatus(201);
        $classId = $classRes->json('id');

        $this->deleteJson("/api/v1/shipping/classes/{$classId}", [], ['X-Tenant-ID' => (string) $this->tenantA->id])
            ->assertStatus(200);

        // Package Type
        $pkgRes = $this->postJson('/api/v1/shipping/package-types', [
            'code' => 'BOX_XL',
            'name' => 'Extra Large Box',
            'max_weight_kg' => '25.0000',
        ], ['X-Tenant-ID' => (string) $this->tenantA->id]);
        $pkgRes->assertStatus(201);
        $pkgId = $pkgRes->json('id');

        $this->deleteJson("/api/v1/shipping/package-types/{$pkgId}", [], ['X-Tenant-ID' => (string) $this->tenantA->id])
            ->assertStatus(200);
    }
}
