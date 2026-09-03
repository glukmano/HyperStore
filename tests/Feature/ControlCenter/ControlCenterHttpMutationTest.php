<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Exceptions\UnauthorizedContextException;
use App\Core\SuperAdmin\Models\OfficialExtension;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\SuperAdmin\Models\TenantLicense;
use App\Core\Tenancy\Enums\TenantOperationalStatus;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlCenterHttpMutationTest extends TestCase
{
    use RefreshDatabase;

    private PlatformSaasPlan $plan;

    private Tenant $tenant;

    private User $tenantOwner;

    private User $tenantStaff;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = PlatformSaasPlan::create([
            'code' => 'http-plan-'.uniqid(),
            'name' => 'HTTP Plan',
            'status' => 'active',
            'limits' => ['max_stores' => 5],
        ]);

        $this->tenant = Tenant::create([
            'name' => 'HTTP Tenant',
            'slug' => 'http-tenant-'.uniqid(),
            'status' => 'active',
        ]);

        app(TenantLicenseServiceInterface::class)->assignLicense($this->tenant->id, $this->plan->id);

        $this->tenantOwner = User::create([
            'name' => 'Owner',
            'email' => 'owner_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->tenantOwner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->tenantStaff = User::create([
            'name' => 'Staff',
            'email' => 'staff_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->tenantStaff->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'sadmin_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => true,
        ]);
    }

    public function test_tenant_owner_can_create_store_via_http(): void
    {
        $this->actingAs($this->tenantOwner);

        $response = $this->postJson(route('control-center.tenant.stores.create', ['tenant' => $this->tenant->id]), [
            'name' => 'My Web Store',
            'slug' => 'my-web-store-'.uniqid(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['store' => ['id', 'name', 'slug', 'tenant_id']]);
        $this->assertDatabaseHas('stores', [
            'tenant_id' => $this->tenant->id,
            'name' => 'My Web Store',
        ]);
    }

    public function test_tenant_staff_cannot_create_store_via_http(): void
    {
        $this->withoutExceptionHandling();
        $this->actingAs($this->tenantStaff);

        $this->expectException(UnauthorizedContextException::class);
        $this->expectExceptionMessage('Role [staff] does not satisfy required role [admin].');

        $this->postJson(route('control-center.tenant.stores.create', ['tenant' => $this->tenant->id]), [
            'name' => 'Staff Store',
            'slug' => 'staff-store-'.uniqid(),
        ]);
    }

    public function test_tenant_owner_can_revoke_staff_membership_via_http(): void
    {
        $this->actingAs($this->tenantOwner);

        $response = $this->postJson(route('control-center.tenant.memberships.revoke', [
            'tenant' => $this->tenant->id,
            'userId' => $this->tenantStaff->id,
        ]));

        $response->assertStatus(200);
        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->tenantStaff->id,
            'is_active' => false,
        ]);
    }

    public function test_tenant_staff_cannot_revoke_member_via_http(): void
    {
        $this->withoutExceptionHandling();
        $this->actingAs($this->tenantStaff);

        $this->expectException(UnauthorizedContextException::class);
        $this->expectExceptionMessage('Role [staff] does not satisfy required role [admin].');

        $this->postJson(route('control-center.tenant.memberships.revoke', [
            'tenant' => $this->tenant->id,
            'userId' => $this->tenantOwner->id,
        ]));
    }

    public function test_tenant_admin_cannot_revoke_owner_membership_via_http(): void
    {
        $adminUser = User::create([
            'name' => 'Admin User',
            'email' => 'admin_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        TenantUser::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $adminUser->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->withoutExceptionHandling();
        $this->actingAs($adminUser);

        $this->expectException(UnauthorizedContextException::class);
        $this->expectExceptionMessage('Only a Tenant Owner can revoke an Owner membership.');

        $this->postJson(route('control-center.tenant.memberships.revoke', [
            'tenant' => $this->tenant->id,
            'userId' => $this->tenantOwner->id,
        ]));
    }

    public function test_revoking_cross_tenant_member_is_rejected_via_http(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other Tenant', 'slug' => 'other-'.uniqid(), 'status' => 'active']);
        $outsider = User::create([
            'name' => 'Outsider',
            'email' => 'out_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        TenantUser::create([
            'tenant_id' => $otherTenant->id,
            'user_id' => $outsider->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->withoutExceptionHandling();
        $this->actingAs($this->tenantOwner);

        $this->expectException(UnauthorizedContextException::class);
        $this->expectExceptionMessage("Target user [{$outsider->id}] does not belong to Tenant [{$this->tenant->id}].");

        $this->postJson(route('control-center.tenant.memberships.revoke', [
            'tenant' => $this->tenant->id,
            'userId' => $outsider->id,
        ]));
    }

    public function test_super_admin_can_suspend_and_activate_tenant_via_http(): void
    {
        $this->actingAs($this->superAdmin);

        // Suspend
        $suspendResponse = $this->postJson(route('control-center.super-admin.tenants.suspend', ['tenant' => $this->tenant->id]), [
            'reason' => 'Non-payment',
        ]);
        $suspendResponse->assertStatus(200);
        $this->assertSame(TenantOperationalStatus::Suspended, $this->tenant->fresh()->status);

        // Activate
        $activateResponse = $this->postJson(route('control-center.super-admin.tenants.activate', ['tenant' => $this->tenant->id]));
        $activateResponse->assertStatus(200);
        $this->assertSame(TenantOperationalStatus::Active, $this->tenant->fresh()->status);
    }

    public function test_super_admin_can_mutate_plan_limits_via_http(): void
    {
        $this->actingAs($this->superAdmin);

        $response = $this->postJson(route('control-center.super-admin.plans.limits', ['plan' => $this->plan->id]), [
            'limits' => ['max_stores' => 10],
        ]);

        $response->assertStatus(200);
        $this->assertSame(10, $this->plan->fresh()->limits['max_stores']);
    }

    public function test_super_admin_can_mutate_license_overrides_via_http(): void
    {
        $this->actingAs($this->superAdmin);

        $response = $this->postJson(route('control-center.super-admin.licenses.overrides', ['tenant' => $this->tenant->id]), [
            'override_limits' => ['max_stores' => 7],
            'override_features' => ['b2b_portal' => true],
        ]);

        $response->assertStatus(200);
        $license = TenantLicense::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame(7, $license->override_limits['max_stores']);
        $this->assertTrue($license->override_features['b2b_portal']);
    }

    public function test_super_admin_can_create_release_via_http(): void
    {
        $this->actingAs($this->superAdmin);

        $response = $this->postJson(route('control-center.super-admin.releases.create'), [
            'version' => '2.5.0',
            'channel' => 'stable',
            'release_notes' => 'Release v2.5.0 notes',
            'compatibility' => ['min_core_version' => '1.0.0'],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('platform_releases', [
            'version' => '2.5.0',
            'channel' => 'stable',
        ]);
    }

    public function test_super_admin_can_approve_extension_via_http(): void
    {
        $extension = OfficialExtension::create([
            'slug' => 'ext-'.uniqid(),
            'name' => 'Stripe Gateway',
            'publisher_name' => 'HyperStore Official',
            'category' => 'payment',
            'status' => 'draft',
            'compatibility_metadata' => ['1.0.0' => ['min_core' => '1.0']],
        ]);

        $this->actingAs($this->superAdmin);

        $response = $this->postJson(route('control-center.super-admin.extensions.approve', ['extension' => $extension->id]), [
            'approved_version' => '1.0.0',
        ]);

        $response->assertStatus(200);
        $this->assertSame('approved', $extension->fresh()->status);
    }

    public function test_super_admin_can_mutate_platform_setting_via_http(): void
    {
        $this->actingAs($this->superAdmin);

        $response = $this->postJson(route('control-center.super-admin.settings.set'), [
            'key' => 'system.maintenance_banner',
            'value' => 'Scheduled maintenance tonight.',
            'is_encrypted' => false,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('platform_settings', [
            'key' => 'system.maintenance_banner',
            'value' => 'Scheduled maintenance tonight.',
        ]);
    }

    public function test_non_super_admin_is_rejected_from_super_admin_mutations_via_http(): void
    {
        $this->withoutExceptionHandling();
        $this->actingAs($this->tenantOwner);

        $this->expectException(UnauthorizedContextException::class);
        $this->expectExceptionMessage('Super Admin privileges are strictly required for this resource.');

        $this->postJson(route('control-center.super-admin.tenants.suspend', ['tenant' => $this->tenant->id]), [
            'reason' => 'Unauthorized attempt',
        ]);
    }
}
