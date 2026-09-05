<?php

declare(strict_types=1);

namespace Tests\Feature\Context;

use App\Core\Context\ContextManager;
use App\Core\Markets\Models\Market;
use App\Core\Markets\Models\StoreMarket;
use App\Core\ReferenceData\Models\Currency;
use App\Core\ReferenceData\Models\Language;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase-18 Owner Delta §3: ResolveContextMiddleware must resolve BEFORE
 * ResolveStorefrontThemeMiddleware, and Theme must only ever CONSUME the
 * already-resolved Store — never independently re-resolve/overwrite
 * Market/Currency/Channel. Proven end-to-end against the real storefront
 * route (routes/web.php), not just each middleware in isolation.
 */
class ContextBeforeThemeOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_market_resolved_via_query_param_survives_through_theme_middleware(): void
    {
        $tenant = Tenant::create(['slug' => 'ctx-theme-tenant-'.uniqid(), 'name' => 'Ctx Theme Tenant', 'status' => 'active']);
        Currency::create(['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_default' => false, 'is_active' => true]);
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'is_default' => true, 'is_active' => true]);
        Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);

        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Ctx Store', 'slug' => 'ctx-store-'.uniqid(), 'status' => 'active']);
        $store->domains()->create(['domain' => 'ctxtheme.test', 'type' => 'primary', 'is_verified' => true, 'canonical' => true]);

        $defaultMarket = Market::create(['tenant_id' => $tenant->id, 'name' => 'Default Market', 'code' => 'DEFAULT', 'is_active' => true, 'default_currency_code' => 'EUR', 'default_locale_code' => 'en', 'timezone' => 'UTC']);
        $swissMarket = Market::create(['tenant_id' => $tenant->id, 'name' => 'Swiss Market', 'code' => 'CH', 'is_active' => true, 'default_currency_code' => 'CHF', 'default_locale_code' => 'en', 'timezone' => 'Europe/Zurich']);

        StoreMarket::create(['store_id' => $store->id, 'market_id' => $defaultMarket->id, 'is_active' => true, 'is_default' => true]);
        StoreMarket::create(['store_id' => $store->id, 'market_id' => $swissMarket->id, 'is_active' => true, 'is_default' => false]);

        // Explicit ?market=CH must resolve the Swiss Market — and Theme
        // middleware (which runs SECOND now) must not clobber it back to
        // the Store's own default Market.
        $response = $this->withHeader('Host', 'ctxtheme.test')->get('/?market=CH');

        $response->assertOk();

        $contextManager = app(ContextManager::class);
        $this->assertTrue($contextManager->getMarket()->isResolved());
        $this->assertSame($swissMarket->id, $contextManager->getMarket()->getId());
        $this->assertSame('CHF', $contextManager->getCurrency()->getCode());
    }

    public function test_theme_middleware_no_longer_independently_sets_market_currency_or_channel(): void
    {
        // Source-level regression: ResolveStorefrontThemeMiddleware must
        // not construct MarketContext/CurrencyContext/ChannelContext at all
        // — those DTOs must not even be imported there any more.
        $contents = file_get_contents(base_path('app/Core/Theme/Http/Middleware/ResolveStorefrontThemeMiddleware.php'));

        $this->assertStringNotContainsString('MarketContext', $contents);
        $this->assertStringNotContainsString('CurrencyContext', $contents);
        $this->assertStringNotContainsString('ChannelContext', $contents);
        $this->assertStringNotContainsString('DomainAddressingService', $contents);
    }

    public function test_storefront_route_group_runs_resolve_context_middleware_before_theme_middleware(): void
    {
        $contents = file_get_contents(base_path('routes/web.php'));

        $pos = strpos($contents, "Route::middleware([ResolveContextMiddleware::class, ResolveStorefrontThemeMiddleware::class])\n    ->group(\$storefrontRoutes);");

        $this->assertNotFalse($pos, 'Storefront group must apply ResolveContextMiddleware before ResolveStorefrontThemeMiddleware, in that exact order.');
    }
}
