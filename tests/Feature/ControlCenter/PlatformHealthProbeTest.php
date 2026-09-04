<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Exceptions\UnauthorizedContextException;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformHealthProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_request_to_health_probe_is_denied(): void
    {
        // Phase-15 authentication-access completion fix (2026-09-04): a JSON-expecting
        // guest request is denied with a standard 401 (Laravel's Authenticate middleware
        // never redirects a JSON request) — never a 500.
        $response = $this->getJson(route('control-center.health'));

        $response->assertStatus(401);
    }

    public function test_tenant_admin_request_to_health_probe_is_denied(): void
    {
        $this->withoutExceptionHandling();

        $plan = PlatformSaasPlan::create([
            'code' => 'plan-'.uniqid(),
            'name' => 'Plan',
            'status' => 'active',
            'limits' => ['max_stores' => 5],
        ]);

        $tenant = Tenant::create([
            'name' => 'Acme',
            'slug' => 'acme-'.uniqid(),
            'status' => 'active',
        ]);

        app(TenantLicenseServiceInterface::class)->assignLicense($tenant->id, $plan->id);

        $tenantAdmin = User::create([
            'name' => 'Tenant Admin',
            'email' => 'tadmin_'.uniqid().'@acme.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $tenantAdmin->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($tenantAdmin);
        $this->expectException(UnauthorizedContextException::class);

        $this->getJson(route('control-center.health'));
    }

    public function test_super_admin_is_permitted_and_returns_diagnostics(): void
    {
        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'sadmin_'.uniqid().'@platform.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => true,
        ]);

        $this->actingAs($superAdmin);

        $response = $this->getJson(route('control-center.health'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'timestamp',
            'checks' => [
                'database' => ['status', 'message'],
                'cache' => ['status', 'message'],
            ],
        ]);
        $this->assertSame('healthy', $response->json('status'));
        $this->assertSame('ok', $response->json('checks.database.status'));
        $this->assertSame('ok', $response->json('checks.cache.status'));
    }
}
