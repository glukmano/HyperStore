<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

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

class ControlCenterAuthenticationAndContextTest extends TestCase
{
    use RefreshDatabase;

    private PlatformSaasPlan $plan;

    private Tenant $tenant;

    private User $tenantOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = PlatformSaasPlan::create([
            'code' => 'std-plan',
            'name' => 'Standard Plan',
            'status' => 'active',
            'limits' => ['max_stores' => 5],
        ]);

        $this->tenant = Tenant::create([
            'name' => 'Acme Tenant',
            'slug' => 'acme',
            'status' => 'active',
        ]);

        app(TenantLicenseServiceInterface::class)->assignLicense($this->tenant->id, $this->plan->id);

        $this->tenantOwner = User::create([
            'name' => 'Owner',
            'email' => 'owner@acme.com',
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
    }

    public function test_unauthenticated_request_redirects_to_login_instead_of_erroring(): void
    {
        // Phase-15 authentication-access completion fix (2026-09-04): a guest hitting
        // any Control Center route must never 500 — the `auth` middleware (now placed
        // before ControlCenterContextMiddleware in routes/web.php) intercepts first and
        // redirects to the named `login` route, preserving the intended URL.
        $response = $this->get(route('control-center.tenant.dashboard', ['tenant' => $this->tenant->id]));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_without_tenant_membership_gets_403_not_500(): void
    {
        $outsider = User::create([
            'name' => 'Outsider 403',
            'email' => 'outsider-403@other.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        $this->actingAs($outsider);

        $response = $this->get(route('control-center.tenant.dashboard', ['tenant' => $this->tenant->id]));

        $response->assertStatus(403);
    }

    public function test_authenticated_user_without_tenant_membership_fails_closed(): void
    {
        $this->withoutExceptionHandling();
        $outsider = User::create([
            'name' => 'Outsider',
            'email' => 'outsider@other.com',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => false,
        ]);

        $this->actingAs($outsider);

        $this->expectException(UnauthorizedContextException::class);

        $this->get(route('control-center.tenant.dashboard', ['tenant' => $this->tenant->id]));
    }

    public function test_authenticated_member_accesses_control_center_cleanly(): void
    {
        $this->actingAs($this->tenantOwner);

        $response = $this->get(route('control-center.tenant.dashboard', ['tenant' => $this->tenant->id]));

        $response->assertStatus(200);
    }

    public function test_suspended_tenant_fails_closed(): void
    {
        $this->withoutExceptionHandling();
        $this->tenant->status = 'suspended';
        $this->tenant->save();

        $this->actingAs($this->tenantOwner);

        $this->expectException(TenantSuspendedException::class);

        $this->get(route('control-center.tenant.dashboard', ['tenant' => $this->tenant->id]));
    }

    public function test_inactive_license_fails_closed(): void
    {
        $this->withoutExceptionHandling();
        app(TenantLicenseServiceInterface::class)->suspendLicense($this->tenant->id);

        $this->actingAs($this->tenantOwner);

        $this->expectException(TenantLicenseInactiveException::class);

        $this->get(route('control-center.tenant.dashboard', ['tenant' => $this->tenant->id]));
    }
}
