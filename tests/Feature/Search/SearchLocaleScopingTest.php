<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use Modules\Search\Services\ScoutSearchService;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase-18 Owner Delta §12: a search in one Locale must only rank on that
 * Locale's own fields plus the configured fallback — never every
 * registered Locale's fields equally (which would let an unrelated-
 * language translation leak relevance into the result set).
 */
class SearchLocaleScopingTest extends TestCase
{
    private function invoke(string $locale, array $prefixes): array
    {
        $service = new ScoutSearchService;
        $method = (new ReflectionClass($service))->getMethod('localeScopedAttributes');

        return $method->invoke($service, $locale, $prefixes);
    }

    public function test_requested_locale_fields_are_included(): void
    {
        config(['app.fallback_locale' => 'en']);

        $attrs = $this->invoke('de-CH', ['name', 'description']);

        $this->assertContains('name_de-CH', $attrs);
        $this->assertContains('description_de-CH', $attrs);
    }

    public function test_fallback_locale_fields_are_appended_after_the_requested_locale(): void
    {
        config(['app.fallback_locale' => 'en']);

        $attrs = $this->invoke('ar', ['name']);

        $this->assertSame(['name_ar', 'name_en'], $attrs);
    }

    public function test_when_requested_locale_equals_fallback_no_duplicate_fields_are_produced(): void
    {
        config(['app.fallback_locale' => 'en']);

        $attrs = $this->invoke('en', ['name', 'description']);

        $this->assertSame(['name_en', 'description_en'], $attrs);
    }

    public function test_an_unrelated_locale_field_is_never_included(): void
    {
        config(['app.fallback_locale' => 'en']);

        $attrs = $this->invoke('de-CH', ['name']);

        $this->assertNotContains('name_ar', $attrs);
        $this->assertNotContains('name_fr-CH', $attrs);
    }
}
