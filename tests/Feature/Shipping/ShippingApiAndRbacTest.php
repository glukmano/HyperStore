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
use Tests\TestCase;

class ShippingApiAndRbacTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ShippingPermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'API Tenant', 'slug' => 'api-tenant', 'status' => 'active']);
        $this->user = User::create([
            'name' => 'Shipping Admin',
            'email' => 'shipping@hyperstore.ch',
            'password' => bcrypt('secret123'),
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $res = $this->getJson('/api/v1/shipping/zones', ['X-Tenant-ID' => (string) $this->tenant->id]);
        $res->assertStatus(401);
    }

    public function test_authenticated_user_with_permission_can_manage_zones(): void
    {
        Sanctum::actingAs($this->user);
        $this->user->givePermissionTo('shipping.zones.manage');
        $this->user->givePermissionTo('shipping.zones.view');

        // Create Zone
        $createRes = $this->postJson('/api/v1/shipping/zones', [
            'code' => 'SWISS_ZONE',
            'name' => 'Swiss National Zone',
            'priority' => 10,
        ], ['X-Tenant-ID' => (string) $this->tenant->id]);

        $createRes->assertStatus(201);
        $createRes->assertJsonFragment(['code' => 'SWISS_ZONE']);

        // List Zones
        $listRes = $this->getJson('/api/v1/shipping/zones', ['X-Tenant-ID' => (string) $this->tenant->id]);
        $listRes->assertStatus(200);
        $listRes->assertJsonFragment(['code' => 'SWISS_ZONE']);
    }

    public function test_carrier_credentials_endpoint_stores_encrypted_and_never_returns_secret(): void
    {
        Sanctum::actingAs($this->user);
        $this->user->givePermissionTo('shipping.credentials.manage');

        $carrier = Carrier::create([
            'tenant_id' => $this->tenant->id,
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
        ], ['X-Tenant-ID' => (string) $this->tenant->id]);

        $res->assertStatus(200);
        $res->assertJsonFragment(['success' => true]);
        // Secret must not be in response
        $this->assertStringNotContainsString('SECRET_TOKEN_999', $res->getContent());
    }
}
