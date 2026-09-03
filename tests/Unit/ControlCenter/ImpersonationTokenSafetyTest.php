<?php

declare(strict_types=1);

namespace Tests\Unit\ControlCenter;

use App\Core\SuperAdmin\Contracts\ImpersonationServiceInterface;
use App\Core\SuperAdmin\Exceptions\NestedImpersonationForbiddenException;
use App\Core\SuperAdmin\Exceptions\SuperAdminImpersonationForbiddenException;
use App\Models\User;
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
            'password' => 'secret123', 'status' => 'active',
        ]);

        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super@test.com',
            'is_super_admin' => true,
            'password' => 'secret123', 'status' => 'active',
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
}
