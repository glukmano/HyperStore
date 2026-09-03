<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\SuperAdmin\Contracts\ImpersonationServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Exceptions\TenantLicenseInactiveException;
use App\Core\SuperAdmin\Exceptions\TenantSuspendedException;
use App\Core\SuperAdmin\Exceptions\UnauthorizedContextException;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationEffectiveIdentityContextTest extends TestCase
{
    use RefreshDatabase;

    private PlatformSaasPlan $plan;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $superAdmin;

    private User $ownerA;

    private User $unaffiliatedUser;

    private ImpersonationServiceInterface $impersonationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = PlatformSaasPlan::create([
            'code' => 'eff-plan-'.uniqid(),
            'name' => 'Effective Identity Plan',
            'status' => 'active',
            'limits' => ['max_stores' => 5],
        ]);

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-'.uniqid(),
            'status' => 'active',
        ]);

        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-'.uniqid(),
            'status' => 'active',
        ]);

        app(TenantLicenseServiceInterface::class)->assignLicense($this->tenantA->id, $this->plan->id);
        app(TenantLicenseServiceInterface::class)->assignLicense($this->tenantB->id, $this->plan->id);

        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'sadmin_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => true,
        ]);

        $this->ownerA = User::create([
            'name' => 'Owner A',
            'email' => 'owner_a_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        TenantUser::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->ownerA->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->unaffiliatedUser = User::create([
            'name' => 'Unaffiliated',
            'email' => 'unaff_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        $this->impersonationService = app(ImpersonationServiceInterface::class);
    }

    public function test_a_super_admin_impersonating_tenant_owner_permitted_when_tenant_and_license_active(): void
    {
        $session = $this->impersonationService->startSession(
            impersonatorUserId: $this->superAdmin->id,
            targetUserId: $this->ownerA->id,
            tenantId: $this->tenantA->id,
            storeId: null,
            vendorId: null,
            reason: 'Support investigation',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $this->actingAs($this->superAdmin);

        $response = $this->withHeader('X-Impersonation-Token', $session['token'])
            ->get(route('control-center.tenant.dashboard', ['tenant' => $this->tenantA->id]));

        $response->assertStatus(200);
    }

    public function test_b_super_admin_impersonating_tenant_owner_denied_when_tenant_is_suspended(): void
    {
        $this->tenantA->status = 'suspended';
        $this->tenantA->save();

        $session = $this->impersonationService->startSession(
            impersonatorUserId: $this->superAdmin->id,
            targetUserId: $this->ownerA->id,
            tenantId: $this->tenantA->id,
            storeId: null,
            vendorId: null,
            reason: 'Support investigation on suspended tenant',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $this->withoutExceptionHandling();
        $this->actingAs($this->superAdmin);

        $this->expectException(TenantSuspendedException::class);

        $this->withHeader('X-Impersonation-Token', $session['token'])
            ->get(route('control-center.tenant.dashboard', ['tenant' => $this->tenantA->id]));
    }

    public function test_c_super_admin_impersonating_tenant_owner_denied_when_license_is_suspended(): void
    {
        app(TenantLicenseServiceInterface::class)->suspendLicense($this->tenantA->id);

        $session = $this->impersonationService->startSession(
            impersonatorUserId: $this->superAdmin->id,
            targetUserId: $this->ownerA->id,
            tenantId: $this->tenantA->id,
            storeId: null,
            vendorId: null,
            reason: 'Support investigation on unlicensed tenant',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $this->withoutExceptionHandling();
        $this->actingAs($this->superAdmin);

        $this->expectException(TenantLicenseInactiveException::class);

        $this->withHeader('X-Impersonation-Token', $session['token'])
            ->get(route('control-center.tenant.dashboard', ['tenant' => $this->tenantA->id]));
    }

    public function test_d_super_admin_impersonating_user_without_tenant_membership_is_denied(): void
    {
        // Unaffiliated user has NO membership in Tenant A, but session specifies Tenant A
        $session = $this->impersonationService->startSession(
            impersonatorUserId: $this->superAdmin->id,
            targetUserId: $this->unaffiliatedUser->id,
            tenantId: $this->tenantA->id,
            storeId: null,
            vendorId: null,
            reason: 'Testing unauthorized membership under impersonation',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $this->withoutExceptionHandling();
        $this->actingAs($this->superAdmin);

        $this->expectException(UnauthorizedContextException::class);
        $this->expectExceptionMessage("User does not hold membership in Tenant [{$this->tenantA->id}].");

        $this->withHeader('X-Impersonation-Token', $session['token'])
            ->get(route('control-center.tenant.dashboard', ['tenant' => $this->tenantA->id]));
    }

    public function test_e_null_context_impersonation_session_denies_access_to_unaffiliated_tenant_b(): void
    {
        // Session has tenant_id = null; target belongs only to Tenant A
        $session = $this->impersonationService->startSession(
            impersonatorUserId: $this->superAdmin->id,
            targetUserId: $this->ownerA->id,
            tenantId: null,
            storeId: null,
            vendorId: null,
            reason: 'Global impersonation without pre-bound tenant',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $this->withoutExceptionHandling();
        $this->actingAs($this->superAdmin);

        // Owner A does not belong to Tenant B -> must fail closed
        $this->expectException(UnauthorizedContextException::class);
        $this->expectExceptionMessage("User does not hold membership in Tenant [{$this->tenantB->id}].");

        $this->withHeader('X-Impersonation-Token', $session['token'])
            ->get(route('control-center.tenant.dashboard', ['tenant' => $this->tenantB->id]));
    }

    public function test_f_null_context_impersonation_session_permits_access_to_entitled_tenant_a(): void
    {
        // Session has tenant_id = null; target belongs to Tenant A
        $session = $this->impersonationService->startSession(
            impersonatorUserId: $this->superAdmin->id,
            targetUserId: $this->ownerA->id,
            tenantId: null,
            storeId: null,
            vendorId: null,
            reason: 'Global impersonation accessing entitled tenant',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $this->actingAs($this->superAdmin);

        $response = $this->withHeader('X-Impersonation-Token', $session['token'])
            ->get(route('control-center.tenant.dashboard', ['tenant' => $this->tenantA->id]));

        $response->assertStatus(200);
    }

    public function test_g_non_impersonated_super_admin_can_still_reach_management_routes_for_suspended_tenant(): void
    {
        // Tenant A is suspended
        $this->tenantA->status = 'suspended';
        $this->tenantA->save();

        $this->actingAs($this->superAdmin);

        // Super Admin management route to activate the suspended tenant
        $response = $this->postJson(route('control-center.super-admin.tenants.activate', ['tenant' => $this->tenantA->id]));

        $response->assertStatus(200);
        $this->assertSame('active', $this->tenantA->fresh()->status->value);
    }
}
