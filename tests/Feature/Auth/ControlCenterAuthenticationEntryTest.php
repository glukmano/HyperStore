<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase-15 authentication-access completion fix (2026-09-04): proves the minimal
 * first-party web login/logout entry point exists and that no normal
 * authentication/authorization denial path produces an HTTP 500.
 */
class ControlCenterAuthenticationEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Login Test User',
            'email' => 'login-test@hyperstore.test',
            'password' => bcrypt('correct-password'),
            'status' => 'active',
            'is_super_admin' => false,
        ]);
    }

    public function test_login_page_renders_for_a_guest(): void
    {
        $response = $this->get(route('login'));

        $response->assertStatus(200);
        $response->assertSee('Email');
        $response->assertSee('Password');
    }

    public function test_valid_credentials_authenticate_and_redirect_to_the_dashboard(): void
    {
        $response = $this->post(route('login.store'), [
            'email' => 'login-test@hyperstore.test',
            'password' => 'correct-password',
        ]);

        $response->assertRedirect(route('control-center.dashboard'));
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_invalid_credentials_fail_safely_without_authenticating(): void
    {
        $response = $this->post(route('login.store'), [
            'email' => 'login-test@hyperstore.test',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_unknown_email_fails_safely_without_authenticating(): void
    {
        $response = $this->post(route('login.store'), [
            'email' => 'does-not-exist@hyperstore.test',
            'password' => 'whatever',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_guest_hitting_bare_control_center_redirects_to_login(): void
    {
        $response = $this->get('/control-center');

        $response->assertRedirect(route('login'));
        $response->assertStatus(302);
    }

    public function test_intended_url_is_restored_after_login(): void
    {
        $tenant = Tenant::create(['name' => 'Intended Tenant', 'slug' => 'intended-tenant', 'status' => 'active']);

        $intendedUrl = route('control-center.tenant.dashboard', ['tenant' => $tenant->id]);

        // First hit as a guest, which stores the intended URL in the session.
        $this->get($intendedUrl)->assertRedirect(route('login'));

        $response = $this->post(route('login.store'), [
            'email' => 'login-test@hyperstore.test',
            'password' => 'correct-password',
        ]);

        $response->assertRedirect($intendedUrl);
    }

    public function test_super_admin_route_rejects_a_non_super_admin_with_403_not_500(): void
    {
        $this->actingAs($this->user);

        $response = $this->get(route('control-center.super-admin.dashboard'));

        $response->assertStatus(403);
    }

    public function test_super_admin_route_is_reachable_by_a_real_super_admin(): void
    {
        $superAdmin = User::create([
            'name' => 'Real Super Admin',
            'email' => 'real-super-admin@hyperstore.test',
            'password' => bcrypt('secret123'),
            'status' => 'active',
            'is_super_admin' => true,
        ]);

        $this->actingAs($superAdmin);

        $response = $this->get(route('control-center.super-admin.dashboard'));

        $response->assertStatus(200);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $this->actingAs($this->user);
        $this->assertAuthenticated();

        $response = $this->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_guest_cannot_logout(): void
    {
        $response = $this->post(route('logout'));

        // Guests hitting the auth-guarded logout route are redirected to login,
        // not a 500 — same auth middleware, same non-500 guarantee.
        $response->assertRedirect(route('login'));
    }

    public function test_the_existing_seeded_development_super_admin_can_log_in_and_reach_the_control_center(): void
    {
        // Proves the exact pre-existing dev account (database/seeders/DatabaseSeeder.php)
        // is real, reachable via this login flow, and reaches the Control Center —
        // not a newly invented credential.
        $this->seed(DatabaseSeeder::class);

        $response = $this->post(route('login.store'), [
            'email' => 'admin@hyperstore.test',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('control-center.dashboard'));
        $this->assertAuthenticated();

        $dashboard = $this->get(route('control-center.dashboard'));
        $dashboard->assertStatus(200);
    }
}
