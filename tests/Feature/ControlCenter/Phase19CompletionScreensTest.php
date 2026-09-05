<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\StoreContext;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Livewire\ControlCenter\CustomerReferralManager;
use App\Livewire\Storefront\Account\ReferralSharePage;
use App\Models\User;
use Database\Seeders\Phase19PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Customers\Models\CustomerProfile;
use Modules\Customers\Services\CustomerReferralService;
use Modules\Promotions\Livewire\LoyaltyAccountPanel;
use Modules\Promotions\Livewire\LoyaltyProgramManager;
use Modules\Promotions\Models\LoyaltyProgram;
use Modules\Promotions\Models\LoyaltyProgramCurrencyRule;
use Tests\TestCase;

/**
 * Phase-19 Final Completion Delta: the previously-missing Control Center
 * and storefront UI screens for Loyalty configuration and Customer referral
 * visibility.
 */
class Phase19CompletionScreensTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $superAdmin;

    private User $plainUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(Phase19PermissionSeeder::class);

        $this->tenant = Tenant::create(['slug' => 'cc-p19-tenant-'.uniqid(), 'name' => 'CC P19 Tenant', 'status' => 'active']);
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Main', 'slug' => 'cc-p19-store-'.uniqid(), 'status' => 'active']);
        $this->superAdmin = User::create(['name' => 'Admin', 'email' => 'cc19admin-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => true]);
        $this->plainUser = User::create(['name' => 'Plain', 'email' => 'cc19plain-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));
        app(ContextManager::class)->setStore(StoreContext::from($store->id, $store->slug));
    }

    public function test_loyalty_program_manager_lets_an_admin_configure_the_program_and_currency_rules(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(LoyaltyProgramManager::class)
            ->set('name', 'Rewards')
            ->set('isActive', true)
            ->set('pendingHoldDays', 3)
            ->set('pointsExpireAfterDays', 365)
            ->set('referralRewardPoints', 750)
            ->call('saveProgram')
            ->assertHasNoErrors();

        $program = LoyaltyProgram::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame('Rewards', $program->name);
        $this->assertSame(750, $program->referral_reward_points);

        Livewire::test(LoyaltyProgramManager::class)
            ->set('ruleCurrency', 'usd')
            ->set('ruleMinorUnitsPerPoint', 100)
            ->set('ruleRedemptionValueMinor', 5)
            ->call('saveCurrencyRule')
            ->assertHasNoErrors();

        $rule = LoyaltyProgramCurrencyRule::where('loyalty_program_id', $program->id)->firstOrFail();
        $this->assertSame('USD', $rule->currency);
    }

    public function test_saving_loyalty_program_settings_is_denied_to_a_plain_user(): void
    {
        // Grant view-only access so mount() succeeds and the test isolates
        // the saveProgram() action's own 'loyalty.manage' gate specifically.
        $this->plainUser->givePermissionTo('loyalty.view');
        $this->actingAs($this->plainUser);

        Livewire::test(LoyaltyProgramManager::class)
            ->set('name', 'Hack')
            ->call('saveProgram')
            ->assertForbidden();
    }

    public function test_customer_referral_manager_is_visible_to_an_authorized_admin(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(CustomerReferralManager::class)->assertOk();
    }

    public function test_customer_referral_manager_is_denied_to_a_plain_user(): void
    {
        $this->actingAs($this->plainUser);

        Livewire::test(CustomerReferralManager::class)->assertForbidden();
    }

    public function test_referral_share_page_shows_the_customers_own_code_and_link(): void
    {
        $this->actingAs($this->plainUser);
        $profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->plainUser->id]);
        $code = app(CustomerReferralService::class)->getOrCreateCode($profile);

        Livewire::test(ReferralSharePage::class)
            ->assertOk()
            ->assertSee($code->code);
    }

    public function test_loyalty_account_panel_shows_the_customers_own_balance(): void
    {
        $this->actingAs($this->plainUser);
        CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->plainUser->id]);

        Livewire::test(LoyaltyAccountPanel::class)->assertOk();
    }
}
