<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Pre-Production Readiness (C.11) — a targeted RBAC boundary check for the
 * Developer Center and Platform screens, complementing the existing
 * Impersonation / Quota Admission / ControlCenterAuthenticationAndContextTest
 * coverage (verified present — see docs/qa/LOCAL-PRODUCTION-READINESS.md).
 * A hidden nav item is NOT an authorization boundary — server-side
 * permission checks are what these assertions target.
 */
class RolePermissionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithStore(): Tenant
    {
        $plan = PlatformSaasPlan::create(['code' => 'rbac-plan', 'name' => 'RBAC Plan', 'status' => 'active', 'limits' => ['max_stores' => 5]]);
        $tenant = Tenant::create(['name' => 'RBAC Tenant', 'slug' => 'rbac-tenant', 'status' => 'active']);
        app(TenantLicenseServiceInterface::class)->assignLicense($tenant->id, $plan->id);

        return $tenant;
    }

    public function test_a_plain_customer_cannot_access_the_developer_center(): void
    {
        $tenant = $this->tenantWithStore();
        $customer = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($customer)
            ->withHeaders(['X-Tenant-ID' => (string) $tenant->id])
            ->get(route('control-center.platform.developer.docs.index'))
            ->assertForbidden();
    }

    public function test_a_plain_customer_cannot_access_pos_registers(): void
    {
        $tenant = $this->tenantWithStore();
        $customer = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($customer)
            ->withHeaders(['X-Tenant-ID' => (string) $tenant->id])
            ->get(route('control-center.pos.registers'))
            ->assertForbidden();
    }

    public function test_a_user_with_developer_docs_permission_can_view_it(): void
    {
        $tenant = $this->tenantWithStore();
        $user = User::factory()->create(['is_super_admin' => false]);
        Permission::firstOrCreate(['name' => 'developer.docs.view', 'guard_name' => 'web']);
        $user->givePermissionTo('developer.docs.view');

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => (string) $tenant->id])
            ->get(route('control-center.platform.developer.docs.index'))
            ->assertOk();
    }

    public function test_direct_url_access_is_gated_the_same_as_navigation_visibility(): void
    {
        // A hidden sidebar link is not a security boundary — a user without
        // the permission must be rejected even hitting the URL directly.
        $tenant = $this->tenantWithStore();
        $user = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($user)
            ->withHeaders(['X-Tenant-ID' => (string) $tenant->id])
            ->get(route('control-center.platform.plugins.index'))
            ->assertForbidden();
    }
}
