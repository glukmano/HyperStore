<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\SuperAdmin\Contracts\ImpersonationServiceInterface;
use App\Core\SuperAdmin\Exceptions\ImpersonationRevokedException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationSessionAndEventAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_impersonation_session_lifecycle_and_append_only_event_audit(): void
    {
        $impersonator = User::create(['name' => 'Admin', 'email' => 'adm@test.com', 'password' => bcrypt('secret123'), 'status' => 'active', 'is_super_admin' => true]);
        $target = User::create(['name' => 'Target', 'email' => 'tgt@test.com', 'password' => bcrypt('secret123'), 'status' => 'active', 'is_super_admin' => false]);

        $service = app(ImpersonationServiceInterface::class);

        // 1. Start session
        $result = $service->startSession(
            impersonatorUserId: $impersonator->id,
            targetUserId: $target->id,
            tenantId: null,
            storeId: null,
            vendorId: null,
            reason: 'Audit investigation',
            ipAddress: '192.168.1.1',
            userAgent: 'Mozilla/5.0'
        );

        $session = $result['session'];
        $token = $result['token'];

        $this->assertTrue($session->isActive());
        $this->assertDatabaseHas('impersonation_sessions', [
            'uuid' => $session->uuid,
            'status' => 'active',
        ]);

        // Event: started
        $this->assertDatabaseHas('impersonation_events', [
            'session_uuid' => $session->uuid,
            'event_type' => 'started',
            'actor_id' => $impersonator->id,
        ]);

        // 2. Authenticate token
        $authSession = $service->authenticateToken($token);
        $this->assertSame($session->id, $authSession->id);

        // 3. Revoke session
        $service->revokeSession($session->uuid, 'Investigation completed', $impersonator->id);

        $this->assertSame('revoked', $session->fresh()->status);

        // Event: revoked
        $this->assertDatabaseHas('impersonation_events', [
            'session_uuid' => $session->uuid,
            'event_type' => 'revoked',
            'actor_id' => $impersonator->id,
        ]);

        // 4. Verification that token is now rejected
        $this->expectException(ImpersonationRevokedException::class);
        $service->authenticateToken($token);
    }
}
