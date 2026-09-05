<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\CurrencyContext;
use App\Core\Context\DTOs\MarketContext;
use App\Core\Context\DTOs\StoreContext;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Markets\Models\Market;
use App\Core\Markets\Models\StoreMarket;
use App\Core\ReferenceData\Models\Currency;
use App\Core\ReferenceData\Models\Language;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Livewire\Storefront\RegionalSwitcher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Customers\Models\CustomerProfile;
use Tests\TestCase;

/**
 * Phase-18 Final Completion Delta §1: the storefront regional switcher —
 * built entirely on the already-completed context/resolution system,
 * hiding a selector outright when there is nothing meaningful to switch,
 * validating every selection against current Store/Market eligibility.
 */
class RegionalSwitcherTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'rs-tenant-'.uniqid(), 'name' => 'RS Tenant', 'status' => 'active']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'RS Store', 'slug' => 'rs-store-'.uniqid(), 'status' => 'active']);
        $this->store->domains()->create(['domain' => 'rs-'.uniqid().'.test', 'type' => 'primary', 'is_verified' => true, 'canonical' => true]);
    }

    private function makeSwissMarket(): Market
    {
        Currency::create(['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_default' => true, 'is_active' => true]);
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_default' => false, 'is_active' => true]);
        Language::create(['code' => 'de-CH', 'name' => 'German (CH)', 'native_name' => 'Deutsch (CH)', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);
        Language::create(['code' => 'fr-CH', 'name' => 'French (CH)', 'native_name' => 'Français (CH)', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);

        $market = Market::create(['tenant_id' => $this->tenant->id, 'name' => 'Switzerland', 'code' => 'CH', 'is_active' => true, 'default_currency_code' => 'CHF', 'default_locale_code' => 'de-CH', 'timezone' => 'Europe/Zurich']);
        $market->marketLanguages()->create(['locale_code' => 'de-CH', 'is_default' => true]);
        $market->marketLanguages()->create(['locale_code' => 'fr-CH', 'is_default' => false]);
        $market->marketCurrencies()->create(['currency_code' => 'CHF', 'is_default' => true]);
        $market->marketCurrencies()->create(['currency_code' => 'EUR', 'is_default' => false]);
        StoreMarket::create(['store_id' => $this->store->id, 'market_id' => $market->id, 'is_active' => true, 'is_default' => true]);

        return $market;
    }

    /**
     * Livewire::test() does not run the real HTTP middleware stack, so the
     * Tenant/Store/Market/Currency context ResolveContextMiddleware would
     * normally set must be provided directly.
     */
    private function resolveContextFor(Market $market): void
    {
        $contextManager = app(ContextManager::class);
        $contextManager->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));
        $contextManager->setStore(StoreContext::from($this->store->id, $this->store->slug));
        $contextManager->setMarket(MarketContext::from($market->id, $market->code));
        $contextManager->setCurrency(CurrencyContext::from($market->default_currency_code));
    }

    public function test_no_selectors_render_when_the_store_has_only_one_locale_currency_and_market(): void
    {
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'is_default' => true, 'is_active' => true]);
        Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);
        $market = Market::create(['tenant_id' => $this->tenant->id, 'name' => 'Default', 'code' => 'DEFAULT', 'is_active' => true, 'default_currency_code' => 'USD', 'default_locale_code' => 'en', 'timezone' => 'UTC']);
        $market->marketLanguages()->create(['locale_code' => 'en', 'is_default' => true]);
        $market->marketCurrencies()->create(['currency_code' => 'USD', 'is_default' => true]);
        StoreMarket::create(['store_id' => $this->store->id, 'market_id' => $market->id, 'is_active' => true, 'is_default' => true]);

        $response = $this->withHeader('Host', $this->store->domains()->first()->domain)->get('/');

        $response->assertOk();
        $response->assertDontSee('select-bordered', false);
    }

    public function test_multi_locale_market_shows_the_locale_selector_in_the_real_rendered_page(): void
    {
        $this->makeSwissMarket();

        $response = $this->withHeader('Host', $this->store->domains()->first()->domain)->get('/');
        $response->assertOk();
        $response->assertSee('select-bordered', false);
    }

    public function test_switching_locale_redirects_to_the_canonical_localized_url(): void
    {
        $market = $this->makeSwissMarket();
        $this->resolveContextFor($market);

        Livewire::test(RegionalSwitcher::class)
            ->set('locale', 'fr-CH')
            ->assertRedirect();
    }

    public function test_switching_to_a_locale_not_in_the_markets_set_is_rejected(): void
    {
        $market = $this->makeSwissMarket();
        $this->resolveContextFor($market);

        Livewire::test(RegionalSwitcher::class)
            ->set('locale', 'ar')
            ->assertNoRedirect();
    }

    public function test_switching_currency_validates_against_market_membership(): void
    {
        $market = $this->makeSwissMarket();
        $this->resolveContextFor($market);

        Livewire::test(RegionalSwitcher::class)
            ->set('currency', 'JPY')
            ->assertNoRedirect();

        $this->assertDatabaseMissing('customer_profiles', ['preferred_currency' => 'JPY']);
    }

    public function test_authenticated_users_preference_is_persisted_on_switch(): void
    {
        $market = $this->makeSwissMarket();
        $user = User::create(['name' => 'Shopper', 'email' => 'shopper-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        $this->actingAs($user);
        $this->resolveContextFor($market);

        Livewire::test(RegionalSwitcher::class)
            ->set('locale', 'fr-CH');

        $profile = CustomerProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame('fr-CH', $profile->preferred_locale);
    }
}
