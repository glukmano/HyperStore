<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\SuperAdmin\Contracts\ImpersonationServiceInterface;
use App\Core\SuperAdmin\Contracts\TenantLicenseServiceInterface;
use App\Core\SuperAdmin\Exceptions\ImpersonationRevokedException;
use App\Core\SuperAdmin\Exceptions\PrivilegedActionBlockedException;
use App\Core\SuperAdmin\Exceptions\UnauthorizedContextException;
use App\Core\SuperAdmin\Models\ImpersonationSession;
use App\Core\SuperAdmin\Models\PlatformSaasPlan;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Models\TenantUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationHttpMutationTest extends TestCase
{
    use RefreshDatabase;

    private PlatformSaasPlan $plan;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $superAdmin;

    private User $targetOwner;

    private User $targetStaff;

    private ImpersonationServiceInterface $impersonationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = PlatformSaasPlan::create([
            'code' => 'imp-plan-'.uniqid(),
            'name' => 'Plan',
            'status' => 'active',
            'limits' => ['max_stores' => 10],
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

        $this->targetOwner = User::create([
            'name' => 'Target Owner',
            'email' => 'owner_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        TenantUser::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->targetOwner->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->targetStaff = User::create([
            'name' => 'Target Staff',
            'email' => 'staff_'.uniqid().'@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        TenantUser::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $this->targetStaff->id,
            'role' => 'staff',
            'is_active' => true,
        ]);

        $this->impersonationService = app(ImpersonationServiceInterface::class);
    }

    public function test_a_super_admin_impersonating_tenant_owner_can_create_store(): void
    {
        $sessionData = $this->impersonationService->startSession(
            impersonatorUserId: $this->superAdmin->id,
            targetUserId: $this->targetOwner->id,
            tenantId: $this->tenantA->id,
            storeId: null,
            vendorId: null,
            reason: 'Assisting tenant owner',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $token = $sessionData['token'];

        $this->actingAs($this->superAdmin);

        $response = $this->withHeader('X-Impersonation-Token', $token)
            ->postJson(route('control-center.tenant.stores.create', ['tenant' => $this->tenantA->id]), [
                'name' => 'Store by Owner Impersonation',
                'slug' => 'st-owner-imp-'.uniqid(),
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('stores', [
            'tenant_id' => $this->tenantA->id,
            'name' => 'Store by Owner Impersonation',
        ]);
    }

    public function test_b_super_admin_impersonating_tenant_staff_cannot_create_store(): void
    {
        $sessionData = $this->impersonationService->startSession(
            impersonatorUserId: $this->superAdmin->id,
            targetUserId: $this->targetStaff->id,
            tenantId: $this->tenantA->id,
            storeId: null,
            vendorId: null,
            reason: 'Assisting tenant staff',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $token = $sessionData['token'];

        $this->withoutExceptionHandling();
        $this->actingAs($this->superAdmin);

        // Staff does not have 'admin' role in TenantA, so authorization fails closed
        $this->expectException(UnauthorizedContextException::class);
        $this->expectExceptionMessage('Role [staff] does not satisfy required role [admin].');

        $this->withHeader('X-Impersonation-Token', $token)
            ->postJson(route('control-center.tenant.stores.create', ['tenant' => $this->tenantA->id]), [
                'name' => 'Store by Staff Impersonation',
                'slug' => 'st-staff-imp-'.uniqid(),
            ]);
    }

    public function test_c_revoked_impersonation_token_is_denied(): void
    {
        $sessionData = $this->impersonationService->startSession(
            impersonatorUserId: $this->superAdmin->id,
            targetUserId: $this->targetOwner->id,
            tenantId: $this->tenantA->id,
            storeId: null,
            vendorId: null,
            reason: 'About to be revoked',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $token = $sessionData['token'];
        $sessionUuid = $sessionData['session']->uuid;

        // Revoke the session
        $this->impersonationService->revokeSession($sessionUuid, 'Emergency revocation');

        $this->withoutExceptionHandling();
        $this->actingAs($this->superAdmin);

        $this->expectException(ImpersonationRevokedException::class);

        $this->withHeader('X-Impersonation-Token', $token)
            ->postJson(route('control-center.tenant.stores.create', ['tenant' => $this->tenantA->id]), [
                'name' => 'Store Post Revocation',
                'slug' => 'st-revoked-'.uniqid(),
            ]);
    }

    public function test_d_expired_impersonation_token_is_denied_with_authoritative_expiry(): void
    {
        $sessionData = $this->impersonationService->startSession(
            impersonatorUserId: $this->superAdmin->id,
            targetUserId: $this->targetOwner->id,
            tenantId: $this->tenantA->id,
            storeId: null,
            vendorId: null,
            reason: 'Testing expiry',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $token = $sessionData['token'];
        /** @var ImpersonationSession $session */
        $session = $sessionData['session'];

        // Expire the session in DB
        $session->expires_at = CarbonImmutable::now()->subMinute();
        $session->save();

        $this->withoutExceptionHandling();
        $this->actingAs($this->superAdmin);

        $this->expectException(ImpersonationRevokedException::class);

        $this->withHeader('X-Impersonation-Token', $token)
            ->postJson(route('control-center.tenant.stores.create', ['tenant' => $this->tenantA->id]), [
                'name' => 'Store Post Expiry',
                'slug' => 'st-exp-'.uniqid(),
            ]);
    }

    public function test_e_impersonation_session_for_tenant_a_fails_closed_when_accessing_tenant_b(): void
    {
        $sessionData = $this->impersonationService->startSession(
            impersonatorUserId: $this->superAdmin->id,
            targetUserId: $this->targetOwner->id,
            tenantId: $this->tenantA->id,
            storeId: null,
            vendorId: null,
            reason: 'Strict tenant containment',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $token = $sessionData['token'];

        $this->withoutExceptionHandling();
        $this->actingAs($this->superAdmin);

        // Requested Tenant B does not match Session Tenant A -> must fail closed
        $this->expectException(UnauthorizedContextException::class);
        $this->expectExceptionMessage("Requested Tenant [{$this->tenantB->id}] does not match impersonation session Tenant [{$this->tenantA->id}].");

        $this->withHeader('X-Impersonation-Token', $token)
            ->postJson(route('control-center.tenant.stores.create', ['tenant' => $this->tenantB->id]), [
                'name' => 'Store Cross Tenant',
                'slug' => 'st-cross-'.uniqid(),
            ]);
    }

    public function test_f_impersonation_target_becoming_inactive_denies_mutation(): void
    {
        $sessionData = $this->impersonationService->startSession(
            impersonatorUserId: $this->superAdmin->id,
            targetUserId: $this->targetOwner->id,
            tenantId: $this->tenantA->id,
            storeId: null,
            vendorId: null,
            reason: 'Target deactivated',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $token = $sessionData['token'];

        // Deactivate the target user
        $this->targetOwner->status = 'suspended';
        $this->targetOwner->save();

        $this->withoutExceptionHandling();
        $this->actingAs($this->superAdmin);

        $this->expectException(UnauthorizedContextException::class);
        $this->expectExceptionMessage('Impersonation target user is not valid or active.');

        $this->withHeader('X-Impersonation-Token', $token)
            ->postJson(route('control-center.tenant.stores.create', ['tenant' => $this->tenantA->id]), [
                'name' => 'Store Target Suspended',
                'slug' => 'st-deact-'.uniqid(),
            ]);
    }

    public function test_g_prohibited_impersonated_action_throws_exception_and_writes_audit_event(): void
    {
        $sessionData = $this->impersonationService->startSession(
            impersonatorUserId: $this->superAdmin->id,
            targetUserId: $this->targetOwner->id,
            tenantId: $this->tenantA->id,
            storeId: null,
            vendorId: null,
            reason: 'Prohibited test',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $token = $sessionData['token'];
        $sessionUuid = $sessionData['session']->uuid;

        $this->withoutExceptionHandling();
        $this->actingAs($this->superAdmin);

        $this->expectException(PrivilegedActionBlockedException::class);
        $this->expectExceptionMessage('Action [credential_mutation] is prohibited while operating under an impersonated session.');

        try {
            $this->withHeader('X-Impersonation-Token', $token)
                ->postJson(route('control-center.tenant.credentials.mutate', ['tenant' => $this->tenantA->id]));
        } finally {
            $this->assertDatabaseHas('impersonation_events', [
                'session_uuid' => $sessionUuid,
                'event_type' => 'privileged_action_blocked',
                'actor_id' => $this->superAdmin->id,
            ]);
        }
    }
}
