<?php

declare(strict_types=1);

namespace Tests\Unit\ControlCenter;

use App\Core\SuperAdmin\Contracts\ImpersonationServiceInterface;
use App\Core\SuperAdmin\Exceptions\ImpersonationRevokedException;
use App\Core\SuperAdmin\Exceptions\NestedImpersonationForbiddenException;
use App\Core\SuperAdmin\Exceptions\PrivilegedActionBlockedException;
use App\Core\SuperAdmin\Exceptions\SuperAdminImpersonationForbiddenException;
use App\Core\SuperAdmin\Models\ImpersonationEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationTokenSafetyTest extends TestCase
{
    use RefreshDatabase;

    private ImpersonationServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ImpersonationServiceInterface::class);
    }

    public function test_impersonating_super_admin_is_strictly_forbidden(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.com',
            'is_super_admin' => false,
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super@test.com',
            'is_super_admin' => true,
            'password' => 'secret123',
            'status' => 'active',
        ]);

        $this->expectException(SuperAdminImpersonationForbiddenException::class);

        $this->service->startSession(
            impersonatorUserId: $admin->id,
            targetUserId: $superAdmin->id,
            tenantId: null,
            storeId: null,
            vendorId: null,
            reason: 'Audit investigation',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );
    }

    public function test_nested_impersonation_is_strictly_forbidden(): void
    {
        $userA = User::create(['name' => 'User A', 'email' => 'a@test.com', 'is_super_admin' => true, 'password' => 'secret123', 'status' => 'active']);
        $userB = User::create(['name' => 'User B', 'email' => 'b@test.com', 'is_super_admin' => false, 'password' => 'secret123', 'status' => 'active']);
        $userC = User::create(['name' => 'User C', 'email' => 'c@test.com', 'is_super_admin' => false, 'password' => 'secret123', 'status' => 'active']);

        // First impersonation session
        $this->service->startSession(
            impersonatorUserId: $userA->id,
            targetUserId: $userB->id,
            tenantId: null,
            storeId: null,
            vendorId: null,
            reason: 'Session 1',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $this->expectException(NestedImpersonationForbiddenException::class);

        // Nested attempt: user A attempting another session while one is active
        $this->service->startSession(
            impersonatorUserId: $userA->id,
            targetUserId: $userC->id,
            tenantId: null,
            storeId: null,
            vendorId: null,
            reason: 'Session 2 (nested attempt)',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );
    }

    public function test_execute_authorized_runs_permitted_mutation_and_returns_result(): void
    {
        $userA = User::create(['name' => 'User A', 'email' => 'a_auth@test.com', 'is_super_admin' => true, 'password' => 'secret123', 'status' => 'active']);
        $userB = User::create(['name' => 'User B', 'email' => 'b_auth@test.com', 'is_super_admin' => false, 'password' => 'secret123', 'status' => 'active']);

        $res = $this->service->startSession(
            impersonatorUserId: $userA->id,
            targetUserId: $userB->id,
            tenantId: null,
            storeId: null,
            vendorId: null,
            reason: 'Permitted action test',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $val = $this->service->executeAuthorized($res['token'], 'update_store_theme', function ($session) {
            return 'theme_updated_for_'.$session->id;
        });

        $this->assertSame('theme_updated_for_'.$res['session']->id, $val);
    }

    public function test_execute_authorized_blocks_prohibited_actions_and_logs_audit_event(): void
    {
        $userA = User::create(['name' => 'User A', 'email' => 'a_blk@test.com', 'is_super_admin' => true, 'password' => 'secret123', 'status' => 'active']);
        $userB = User::create(['name' => 'User B', 'email' => 'b_blk@test.com', 'is_super_admin' => false, 'password' => 'secret123', 'status' => 'active']);

        $res = $this->service->startSession(
            impersonatorUserId: $userA->id,
            targetUserId: $userB->id,
            tenantId: null,
            storeId: null,
            vendorId: null,
            reason: 'Prohibited action test',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        try {
            $this->service->executeAuthorized($res['token'], 'payout_finalization', function ($session) {
                return 'should_never_reach_here';
            });
            $this->fail('Expected PrivilegedActionBlockedException was not thrown.');
        } catch (PrivilegedActionBlockedException $e) {
            $this->assertSame('payout_finalization', $e->action);
        }

        $event = ImpersonationEvent::where('session_uuid', $res['session']->uuid)
            ->where('event_type', 'privileged_action_blocked')
            ->first();

        $this->assertNotNull($event, 'Privileged action block must be recorded in append-only audit log.');
        $this->assertSame('payout_finalization', $event->metadata['action']);
    }

    public function test_execute_authorized_authoritatively_expires_stale_session(): void
    {
        $userA = User::create(['name' => 'User A', 'email' => 'a_exp@test.com', 'is_super_admin' => true, 'password' => 'secret123', 'status' => 'active']);
        $userB = User::create(['name' => 'User B', 'email' => 'b_exp@test.com', 'is_super_admin' => false, 'password' => 'secret123', 'status' => 'active']);

        $res = $this->service->startSession(
            impersonatorUserId: $userA->id,
            targetUserId: $userB->id,
            tenantId: null,
            storeId: null,
            vendorId: null,
            reason: 'Expiry test',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        // Manually backdate expires_at to simulate TTL timeout
        $session = $res['session'];
        $session->expires_at = CarbonImmutable::now()->subMinute();
        $session->save();

        try {
            $this->service->executeAuthorized($res['token'], 'update_store_theme', function ($s) {
                return 'unreachable';
            });
            $this->fail('Expected ImpersonationRevokedException was not thrown.');
        } catch (ImpersonationRevokedException) {
            // Expected
        }

        $session->refresh();
        $this->assertSame('expired', $session->status);
        $this->assertSame('timeout', $session->termination_reason);

        $event = ImpersonationEvent::where('session_uuid', $session->uuid)
            ->where('event_type', 'expired')
            ->first();
        $this->assertNotNull($event);
    }

    public function test_start_session_authoritatively_expires_previous_timed_out_session(): void
    {
        $userA = User::create(['name' => 'User A', 'email' => 'a_seq@test.com', 'is_super_admin' => true, 'password' => 'secret123', 'status' => 'active']);
        $userB = User::create(['name' => 'User B', 'email' => 'b_seq@test.com', 'is_super_admin' => false, 'password' => 'secret123', 'status' => 'active']);
        $userC = User::create(['name' => 'User C', 'email' => 'c_seq@test.com', 'is_super_admin' => false, 'password' => 'secret123', 'status' => 'active']);

        $res1 = $this->service->startSession(
            impersonatorUserId: $userA->id,
            targetUserId: $userB->id,
            tenantId: null,
            storeId: null,
            vendorId: null,
            reason: 'Session 1',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        // Backdate session 1 to expired
        $session1 = $res1['session'];
        $session1->expires_at = CarbonImmutable::now()->subMinute();
        $session1->save();

        // Starting new session should cleanly transition session 1 to expired and succeed
        $res2 = $this->service->startSession(
            impersonatorUserId: $userA->id,
            targetUserId: $userC->id,
            tenantId: null,
            storeId: null,
            vendorId: null,
            reason: 'Session 2',
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit'
        );

        $this->assertSame('expired', $session1->fresh()->status);
        $this->assertSame('active', $res2['session']->status);
    }
}
