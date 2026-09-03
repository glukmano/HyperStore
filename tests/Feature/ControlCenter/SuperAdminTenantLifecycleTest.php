<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\SuperAdmin\Contracts\TenantLifecycleServiceInterface;
use App\Core\SuperAdmin\Exceptions\UnauthorizedContextException;
use App\Core\Tenancy\Enums\TenantOperationalStatus;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminTenantLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_manage_tenant_lifecycle_authoritatively(): void
    {
        $lifecycleService = app(TenantLifecycleServiceInterface::class);

        $tenant = Tenant::create([
            'name' => 'Lifecycle Tenant',
            'slug' => 'lifecycle-tenant',
            'status' => 'provisioning',
        ]);

        // 1. Activate
        $activeTenant = $lifecycleService->activate($tenant->id);
        $this->assertSame(TenantOperationalStatus::Active, $activeTenant->status);

        // 2. Suspend
        $suspendedTenant = $lifecycleService->suspend($tenant->id, 'Billing failure');
        $this->assertSame(TenantOperationalStatus::Suspended, $suspendedTenant->status);
        $this->assertSame('Billing failure', $suspendedTenant->settings['last_status_reason']);

        // 3. Reactivate
        $reactivatedTenant = $lifecycleService->reactivate($tenant->id);
        $this->assertSame(TenantOperationalStatus::Active, $reactivatedTenant->status);

        // 4. Terminate (terminal)
        $terminatedTenant = $lifecycleService->terminate($tenant->id, 'Customer requested cancellation');
        $this->assertSame(TenantOperationalStatus::Terminated, $terminatedTenant->status);
    }

    public function test_super_admin_dashboard_requires_super_admin_privilege(): void
    {
        $this->withoutExceptionHandling();

        $regularUser = User::create([
            'name' => 'Regular',
            'email' => 'regular@test.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        // Regular user blocked with exception
        $this->actingAs($regularUser);
        $this->expectException(UnauthorizedContextException::class);
        $this->get(route('control-center.super-admin.dashboard'));
    }
}
