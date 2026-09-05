<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Core\Localization\Services\LocaleFallbackResolver;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Currency;
use App\Core\ReferenceData\Models\Language;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleFallbackResolverTest extends TestCase
{
    use RefreshDatabase;

    private LocaleFallbackResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new LocaleFallbackResolver;
    }

    public function test_exact_registered_locale_wins(): void
    {
        Language::create(['code' => 'de-CH', 'name' => 'German (CH)', 'native_name' => 'Deutsch (CH)', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);

        $result = $this->resolver->resolveActiveLocale('de-CH');

        $this->assertNotNull($result);
        $this->assertSame('de-CH', $result->code);
    }

    public function test_falls_back_to_bare_language_subtag_when_regional_variant_unregistered(): void
    {
        Language::create(['code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية', 'direction' => 'rtl', 'is_default' => false, 'is_active' => true]);

        // "ar-SY" is not registered, but the bare "ar" is.
        $result = $this->resolver->resolveActiveLocale('ar-SY');

        $this->assertNotNull($result);
        $this->assertSame('ar', $result->code);
    }

    public function test_falls_back_to_market_default_locale_when_requested_is_unregistered(): void
    {
        $tenant = Tenant::create(['slug' => 'lfr-tenant-'.uniqid(), 'name' => 'LFR Tenant', 'status' => 'active']);
        Language::create(['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_default' => false, 'is_active' => true]);
        $market = Market::create(['tenant_id' => $tenant->id, 'name' => 'Germany', 'code' => 'DE-MKT', 'is_active' => true, 'default_currency_code' => 'EUR', 'default_locale_code' => 'de', 'timezone' => 'Europe/Berlin']);

        $result = $this->resolver->resolveActiveLocale('zz-totally-unregistered', $market);

        $this->assertNotNull($result);
        $this->assertSame('de', $result->code);
    }

    public function test_falls_back_to_stores_default_market_when_only_store_given(): void
    {
        $tenant = Tenant::create(['slug' => 'lfr-tenant2-'.uniqid(), 'name' => 'LFR Tenant 2', 'status' => 'active']);
        Language::create(['code' => 'fr', 'name' => 'French', 'native_name' => 'Français', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_default' => false, 'is_active' => true]);
        $market = Market::create(['tenant_id' => $tenant->id, 'name' => 'France', 'code' => 'FR-MKT', 'is_active' => true, 'default_currency_code' => 'EUR', 'default_locale_code' => 'fr', 'timezone' => 'Europe/Paris']);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'FR Store', 'slug' => 'fr-store-'.uniqid(), 'status' => 'active']);
        $store->markets()->attach($market->id, ['is_active' => true, 'is_default' => true]);

        $result = $this->resolver->resolveActiveLocale('zz-unregistered', null, $store);

        $this->assertNotNull($result);
        $this->assertSame('fr', $result->code);
    }

    public function test_falls_back_to_platform_default_language_when_nothing_else_matches(): void
    {
        Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);

        $result = $this->resolver->resolveActiveLocale('zz-unregistered');

        $this->assertNotNull($result);
        $this->assertSame('en', $result->code);
    }

    public function test_returns_null_on_absolute_bootstrap_failure(): void
    {
        $result = $this->resolver->resolveActiveLocale('en');

        $this->assertNull($result);
    }

    public function test_an_inactive_locale_is_never_resolved(): void
    {
        Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);
        Language::create(['code' => 'de', 'name' => 'German', 'native_name' => 'Deutsch', 'direction' => 'ltr', 'is_default' => false, 'is_active' => false]);

        $result = $this->resolver->resolveActiveLocale('de');

        // "de" is inactive, so it must fall all the way through to the
        // platform default ("en"), never return the inactive row.
        $this->assertNotNull($result);
        $this->assertSame('en', $result->code);
    }
}
