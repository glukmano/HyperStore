<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Core\Stores\Models\Store;
use App\Core\Stores\Models\StoreUser;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Customers\Models\CustomerProfile;
use Tests\TestCase;

/**
 * Phase-17 identity prerequisite: storefront customer self-registration,
 * reusing the existing web guard/User/ContextManager — no second identity
 * system. Also proves the previously-unwired App\Core\Customers\CustomerScopeService
 * (Foundation-phase scaffolding with zero callers before this phase) is now
 * correctly invoked to grant store-level customer access.
 */
class CustomerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Reg Test Tenant', 'slug' => 'reg-test-tenant', 'status' => 'active']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Main Store', 'slug' => 'reg-test-store', 'status' => 'active']);
    }

    public function test_registration_page_renders_for_a_guest(): void
    {
        $response = $this->get(route('register'));

        $response->assertStatus(200);
        $response->assertSee('Full Name');
    }

    public function test_a_new_customer_can_register_and_is_authenticated(): void
    {
        Notification::fake();

        $response = $this->withHeaders(['X-Tenant-Slug' => $this->tenant->slug])->post(route('register.store'), [
            'name' => 'Jane Customer',
            'email' => 'jane@example.test',
            'password' => 'correct-password-123',
            'password_confirmation' => 'correct-password-123',
        ]);

        $response->assertRedirect(route('storefront.home'));

        $user = User::where('email', 'jane@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_super_admin);
        $this->assertSame('active', $user->status);
    }

    public function test_registration_never_creates_a_tenant_or_vendor_staff_membership(): void
    {
        $this->withHeaders(['X-Tenant-Slug' => $this->tenant->slug])->post(route('register.store'), [
            'name' => 'Jane Customer',
            'email' => 'jane@example.test',
            'password' => 'correct-password-123',
            'password_confirmation' => 'correct-password-123',
        ]);

        $user = User::where('email', 'jane@example.test')->firstOrFail();

        $this->assertSame(0, $user->tenantMemberships()->count());
    }

    public function test_registration_creates_a_customer_profile_scoped_to_the_resolved_tenant(): void
    {
        $this->withHeaders(['X-Tenant-Slug' => $this->tenant->slug])->post(route('register.store'), [
            'name' => 'Jane Customer',
            'email' => 'jane@example.test',
            'password' => 'correct-password-123',
            'password_confirmation' => 'correct-password-123',
        ]);

        $user = User::where('email', 'jane@example.test')->firstOrFail();

        $profile = CustomerProfile::query()->where('user_id', $user->id)->where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($profile, 'Expected a CustomerProfile to be created for the resolved tenant.');
    }

    public function test_registration_under_a_store_isolated_tenant_grants_store_customer_access(): void
    {
        $this->tenant->customer_account_scope = 'store_isolated';
        $this->tenant->save();

        $this->withHeaders(['X-Tenant-Slug' => $this->tenant->slug])->post(route('register.store'), [
            'name' => 'Jane Customer',
            'email' => 'jane@example.test',
            'password' => 'correct-password-123',
            'password_confirmation' => 'correct-password-123',
        ]);

        $user = User::where('email', 'jane@example.test')->firstOrFail();

        // Previously (pre-Phase-17), CustomerScopeService::grantStoreCustomerAccess()
        // had zero callers anywhere in the codebase — this proves it is now wired.
        $storeAccess = StoreUser::query()
            ->where('store_id', $this->store->id)
            ->where('user_id', $user->id)
            ->where('role', 'customer')
            ->where('is_active', true)
            ->first();

        $this->assertNotNull($storeAccess, 'Expected registration to grant store-scoped customer access for a store_isolated tenant.');
    }

    public function test_duplicate_email_registration_is_rejected(): void
    {
        User::create(['name' => 'Existing', 'email' => 'taken@example.test', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        $response = $this->withHeaders(['X-Tenant-Slug' => $this->tenant->slug])->post(route('register.store'), [
            'name' => 'Jane Customer',
            'email' => 'taken@example.test',
            'password' => 'correct-password-123',
            'password_confirmation' => 'correct-password-123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $response = $this->withHeaders(['X-Tenant-Slug' => $this->tenant->slug])->post(route('register.store'), [
            'name' => 'Jane Customer',
            'email' => 'jane@example.test',
            'password' => 'correct-password-123',
            'password_confirmation' => 'does-not-match',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertNull(User::where('email', 'jane@example.test')->first());
    }
}
