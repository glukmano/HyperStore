<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Core\Context\ContextManager;
use App\Core\ReferenceData\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase-18 Owner Delta §7: every storefront route exists both as a bare
 * URL (canonical when the host already disambiguates Locale) and under a
 * `{locale}` path prefix (canonical when it doesn't) — never a same-URL,
 * cookie-only illusion of a distinct per-locale page.
 */
class LocalizedRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bare_storefront_route_resolves(): void
    {
        Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);

        $this->get('/')->assertOk();
    }

    public function test_the_locale_prefixed_mirror_route_resolves_and_wins_over_query_param(): void
    {
        Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);
        Language::create(['code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية', 'direction' => 'rtl', 'is_default' => false, 'is_active' => true]);

        // The path segment ("de-CH" not even registered) still routes —
        // it's the CONTEXT resolution's job to fall back sensibly, routing
        // itself must not 404 on an unregistered-but-well-formed locale tag.
        $response = $this->get('/de-CH/?lang=ar');

        $response->assertOk();

        $contextManager = app(ContextManager::class);
        // Tier 0 (path prefix) outranks tier 1 (query param) — falls back
        // through the registered-locale chain to the platform default
        // since "de-CH" itself isn't registered, but the path segment
        // was still what was consulted first, not ?lang=ar.
        $this->assertNotSame('ar', $contextManager->getLocale()->getLocale());
    }

    public function test_an_actual_registered_locale_in_the_path_prefix_resolves_exactly(): void
    {
        Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);
        Language::create(['code' => 'ar', 'name' => 'Arabic', 'native_name' => 'العربية', 'direction' => 'rtl', 'is_default' => false, 'is_active' => true]);

        $this->get('/ar/')->assertOk();

        $contextManager = app(ContextManager::class);
        $this->assertSame('ar', $contextManager->getLocale()->getLocale());
        $this->assertSame('rtl', $contextManager->getLocale()->getDirection());
    }

    public function test_malformed_locale_path_segment_does_not_match_the_localized_group_and_falls_through(): void
    {
        Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);

        // Not a well-formed BCP-47-lite tag at all — the {locale} route
        // constraint must reject it outright (404), not silently accept
        // arbitrary path junk as a "locale".
        $response = $this->get('/not_a_locale_at_all_1234/');

        $response->assertNotFound();
    }
}
