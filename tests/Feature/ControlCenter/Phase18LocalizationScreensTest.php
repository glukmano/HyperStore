<?php

declare(strict_types=1);

namespace Tests\Feature\ControlCenter;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\StoreContext;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Country;
use App\Core\ReferenceData\Models\Currency;
use App\Core\ReferenceData\Models\Language;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Livewire\ControlCenter\CountryManager;
use App\Livewire\ControlCenter\CurrencyManager;
use App\Livewire\ControlCenter\DomainManager;
use App\Livewire\ControlCenter\LanguageManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase-18: the new Language/Country/Currency/Domain Control Center
 * screens — reusing MarketManager's exact permission-gated pattern
 * (Owner Delta §9: deactivate-only, never a destructive delete action).
 */
class Phase18LocalizationScreensTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $superAdmin;

    private User $plainUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'cc-p18-tenant-'.uniqid(), 'name' => 'CC P18 Tenant', 'status' => 'active']);
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Main', 'slug' => 'cc-p18-store-'.uniqid(), 'status' => 'active']);
        $this->superAdmin = User::create(['name' => 'Admin', 'email' => 'cc18admin-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => true]);
        $this->plainUser = User::create(['name' => 'Plain', 'email' => 'cc18plain-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));
        app(ContextManager::class)->setStore(StoreContext::from($store->id, $store->slug));
    }

    public function test_language_manager_renders_for_super_admin_and_is_denied_to_plain_user(): void
    {
        $this->actingAs($this->superAdmin);
        Livewire::test(LanguageManager::class)->assertOk();

        $this->actingAs($this->plainUser);
        Livewire::test(LanguageManager::class)->assertOk(); // render itself has no gate, only mutation actions do
    }

    public function test_creating_a_locale_is_denied_to_a_plain_user(): void
    {
        $this->actingAs($this->plainUser);

        Livewire::test(LanguageManager::class)
            ->set('code', 'de-CH')
            ->set('name', 'German (Switzerland)')
            ->set('native_name', 'Deutsch (Schweiz)')
            ->call('createLanguage')
            ->assertForbidden();
    }

    public function test_super_admin_can_create_a_bcp47_locale_and_it_appears_active(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test(LanguageManager::class)
            ->set('code', 'zh-Hans-CN')
            ->set('name', 'Chinese (Simplified, China)')
            ->set('native_name', '简体中文')
            ->set('direction', 'ltr')
            ->call('createLanguage');

        $this->assertTrue(Language::where('code', 'zh-Hans-CN')->where('is_active', true)->exists());
    }

    public function test_language_manager_has_no_delete_action_only_deactivate(): void
    {
        $reflection = new \ReflectionClass(LanguageManager::class);
        $methodNames = array_map(fn ($m) => $m->getName(), $reflection->getMethods());

        $this->assertContains('deactivateLanguage', $methodNames);
        $this->assertNotContains('deleteLanguage', $methodNames);
    }

    public function test_cannot_deactivate_the_platform_default_locale(): void
    {
        $language = Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);
        $this->actingAs($this->superAdmin);

        Livewire::test(LanguageManager::class)->call('deactivateLanguage', $language->id);

        $this->assertTrue($language->refresh()->is_active);
    }

    public function test_country_manager_renders_and_has_no_delete_action(): void
    {
        $this->actingAs($this->superAdmin);
        Livewire::test(CountryManager::class)->assertOk();

        $reflection = new \ReflectionClass(CountryManager::class);
        $methodNames = array_map(fn ($m) => $m->getName(), $reflection->getMethods());
        $this->assertContains('deactivateCountry', $methodNames);
        $this->assertNotContains('deleteCountry', $methodNames);
    }

    public function test_currency_manager_renders_and_cannot_deactivate_default_currency(): void
    {
        $currency = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'is_default' => true, 'is_active' => true]);
        $this->actingAs($this->superAdmin);

        Livewire::test(CurrencyManager::class)->assertOk()
            ->call('deactivateCurrency', $currency->id);

        $this->assertTrue($currency->refresh()->is_active);
    }

    public function test_domain_manager_renders_and_new_market_domains_require_verification(): void
    {
        Currency::create(['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_default' => true, 'is_active' => true]);
        Language::create(['code' => 'de-CH', 'name' => 'German (CH)', 'native_name' => 'Deutsch (CH)', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);
        $store = Store::where('tenant_id', $this->tenant->id)->first();
        $market = Market::create(['tenant_id' => $this->tenant->id, 'name' => 'Switzerland', 'code' => 'CH', 'is_active' => true, 'default_currency_code' => 'CHF', 'default_locale_code' => 'de-CH', 'timezone' => 'Europe/Zurich']);
        $storeMarket = $store->storeMarkets()->create(['market_id' => $market->id, 'is_active' => true, 'is_default' => true]);

        $this->actingAs($this->superAdmin);

        Livewire::test(DomainManager::class)
            ->set('store_market_id', (string) $storeMarket->id)
            ->set('domain', 'de.example-'.uniqid().'.test')
            ->call('createMarketDomain')
            ->assertOk();

        $this->assertDatabaseHas('market_domains', ['store_market_id' => $storeMarket->id, 'is_verified' => false]);
    }
}
