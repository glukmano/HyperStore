<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Core\Markets\Models\Market;
use App\Core\Markets\Models\MarketLanguage;
use App\Core\ReferenceData\Models\Currency;
use App\Core\ReferenceData\Models\Language;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Seo\Services\SeoMetadataService;
use Tests\TestCase;

/**
 * Phase-18 Owner Delta §7/§20: hreflang must emit only real, distinct,
 * crawlable URLs for actually-active Market×Locale combinations that hold
 * genuine content — never a phantom entry, never every config locale
 * regardless of Market membership.
 */
class HreflangMarketTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Market $market;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'hreflang-tenant', 'name' => 'Hreflang Tenant', 'status' => 'active']);
        Currency::create(['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_default' => false, 'is_active' => true]);
        Language::create(['code' => 'de-CH', 'name' => 'German (CH)', 'native_name' => 'Deutsch (CH)', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);
        Language::create(['code' => 'fr-CH', 'name' => 'French (CH)', 'native_name' => 'Français (CH)', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);
        Language::create(['code' => 'it-CH', 'name' => 'Italian (CH), disabled', 'native_name' => 'Italiano (CH)', 'direction' => 'ltr', 'is_default' => false, 'is_active' => false]);

        $this->market = Market::create(['tenant_id' => $this->tenant->id, 'name' => 'Switzerland', 'code' => 'CH', 'is_active' => true, 'default_currency_code' => 'CHF', 'default_locale_code' => 'de-CH', 'timezone' => 'Europe/Zurich']);
        MarketLanguage::create(['market_id' => $this->market->id, 'locale_code' => 'de-CH', 'is_default' => true]);
        MarketLanguage::create(['market_id' => $this->market->id, 'locale_code' => 'fr-CH', 'is_default' => false]);
        MarketLanguage::create(['market_id' => $this->market->id, 'locale_code' => 'it-CH', 'is_default' => false]);
    }

    public function test_only_active_market_member_locales_with_real_content_are_emitted(): void
    {
        $service = app(SeoMetadataService::class);

        $urls = $service->resolveAlternateLocaleUrlsForMarket($this->market, function (string $locale): ?string {
            // Simulate: only de-CH and fr-CH have an actual translation;
            // it-CH is disabled so this closure is never even reached for it.
            return match ($locale) {
                'de-CH' => 'https://example.ch/de-CH/product/foo',
                'fr-CH' => 'https://example.ch/fr-CH/product/foo',
                default => null,
            };
        });

        $this->assertArrayHasKey('de-CH', $urls);
        $this->assertArrayHasKey('fr-CH', $urls);
        $this->assertArrayNotHasKey('it-CH', $urls, 'A disabled Locale must never receive an hreflang entry.');
    }

    public function test_x_default_points_at_the_markets_own_default_locale(): void
    {
        $service = app(SeoMetadataService::class);

        $urls = $service->resolveAlternateLocaleUrlsForMarket($this->market, function (string $locale): ?string {
            return "https://example.ch/{$locale}/";
        });

        $this->assertSame('https://example.ch/de-CH/', $urls['x-default']);
    }

    public function test_no_phantom_entry_when_the_resource_has_no_translation_for_a_member_locale(): void
    {
        $service = app(SeoMetadataService::class);

        $urls = $service->resolveAlternateLocaleUrlsForMarket($this->market, function (string $locale): ?string {
            // Only de-CH genuinely has this resource translated.
            return $locale === 'de-CH' ? 'https://example.ch/de-CH/pages/about' : null;
        });

        $this->assertArrayHasKey('de-CH', $urls);
        $this->assertArrayNotHasKey('fr-CH', $urls);
    }

    public function test_null_market_falls_back_to_the_legacy_config_seam_unchanged(): void
    {
        config(['app.supported_locales' => ['en', 'ar'], 'app.fallback_locale' => 'en']);
        $service = app(SeoMetadataService::class);

        $urls = $service->resolveAlternateLocaleUrlsForMarket(null, fn (string $locale) => "https://example.com/{$locale}");

        $this->assertArrayHasKey('en', $urls);
        $this->assertArrayHasKey('ar', $urls);
        $this->assertSame('https://example.com/en', $urls['x-default']);
    }
}
