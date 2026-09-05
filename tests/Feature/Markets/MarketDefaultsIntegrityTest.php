<?php

declare(strict_types=1);

namespace Tests\Feature\Markets;

use App\Core\Markets\Models\Market;
use App\Core\Markets\Models\MarketCurrency;
use App\Core\Markets\Models\MarketLanguage;
use App\Core\Markets\Services\MarketDefaultsService;
use App\Core\ReferenceData\Models\Currency;
use App\Core\ReferenceData\Models\Language;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase-18 Owner Delta §8/§14: Market.default_locale_code/default_currency_code
 * stay the ONE authoritative fields; market_languages.is_default /
 * market_currencies.is_default are kept transactionally synchronized to
 * them — never an independently-drifting second source of truth.
 */
class MarketDefaultsIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_defaults_creates_the_matching_membership_rows(): void
    {
        $tenant = Tenant::create(['slug' => 'mdi-tenant-'.uniqid(), 'name' => 'MDI Tenant', 'status' => 'active']);
        Currency::create(['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_default' => false, 'is_active' => true]);
        Language::create(['code' => 'de-CH', 'name' => 'German (CH)', 'native_name' => 'Deutsch (CH)', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);

        $market = Market::create(['tenant_id' => $tenant->id, 'name' => 'Switzerland', 'code' => 'CH-'.uniqid(), 'is_active' => true, 'default_currency_code' => 'CHF', 'default_locale_code' => 'de-CH', 'timezone' => 'Europe/Zurich']);

        app(MarketDefaultsService::class)->bootstrapDefaults($market);

        $this->assertTrue(MarketLanguage::where('market_id', $market->id)->where('locale_code', 'de-CH')->where('is_default', true)->exists());
        $this->assertTrue(MarketCurrency::where('market_id', $market->id)->where('currency_code', 'CHF')->where('is_default', true)->exists());
    }

    public function test_setting_a_new_default_locale_flips_the_old_default_off_and_updates_market(): void
    {
        $tenant = Tenant::create(['slug' => 'mdi-tenant2-'.uniqid(), 'name' => 'MDI Tenant 2', 'status' => 'active']);
        Currency::create(['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_default' => false, 'is_active' => true]);
        Language::create(['code' => 'de-CH', 'name' => 'German (CH)', 'native_name' => 'Deutsch (CH)', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);
        Language::create(['code' => 'fr-CH', 'name' => 'French (CH)', 'native_name' => 'Français (CH)', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);

        $market = Market::create(['tenant_id' => $tenant->id, 'name' => 'Switzerland', 'code' => 'CH-'.uniqid(), 'is_active' => true, 'default_currency_code' => 'CHF', 'default_locale_code' => 'de-CH', 'timezone' => 'Europe/Zurich']);
        $service = app(MarketDefaultsService::class);
        $service->bootstrapDefaults($market);

        MarketLanguage::create(['market_id' => $market->id, 'locale_code' => 'fr-CH', 'is_default' => false]);

        $service->setDefaultLocale($market, 'fr-CH');
        $market->refresh();

        $this->assertSame('fr-CH', $market->default_locale_code);
        $this->assertFalse(MarketLanguage::where('market_id', $market->id)->where('locale_code', 'de-CH')->value('is_default'));
        $this->assertTrue(MarketLanguage::where('market_id', $market->id)->where('locale_code', 'fr-CH')->value('is_default'));

        // Never possible for Market.default_locale_code and the pivot's
        // is_default to point at two different locales.
        $this->assertSame(1, MarketLanguage::where('market_id', $market->id)->where('is_default', true)->count());
    }

    public function test_cannot_set_a_default_locale_that_is_not_a_member_of_the_market(): void
    {
        $tenant = Tenant::create(['slug' => 'mdi-tenant3-'.uniqid(), 'name' => 'MDI Tenant 3', 'status' => 'active']);
        Currency::create(['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_default' => false, 'is_active' => true]);
        Language::create(['code' => 'de-CH', 'name' => 'German (CH)', 'native_name' => 'Deutsch (CH)', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);

        $market = Market::create(['tenant_id' => $tenant->id, 'name' => 'Switzerland', 'code' => 'CH-'.uniqid(), 'is_active' => true, 'default_currency_code' => 'CHF', 'default_locale_code' => 'de-CH', 'timezone' => 'Europe/Zurich']);
        app(MarketDefaultsService::class)->bootstrapDefaults($market);

        $this->expectException(InvalidArgumentException::class);
        app(MarketDefaultsService::class)->setDefaultLocale($market, 'it-CH');
    }
}
