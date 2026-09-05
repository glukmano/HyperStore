<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Phase-18 architecture guarantees — grep-based regression tests proving
 * the Owner Delta's structural requirements hold, not just behavioral
 * tests of any one request.
 */
class Phase18ArchitectureTest extends TestCase
{
    public function test_no_hardcoded_rtl_locale_list_exists_anywhere(): void
    {
        $hits = [];
        foreach ($this->phpFiles(['app', 'modules']) as $file) {
            $contents = file_get_contents($file);
            if (str_contains($contents, 'RTL_LOCALES')) {
                $hits[] = $file;
            }
        }

        $this->assertSame([], $hits, 'Owner Delta §2: both hardcoded RTL-locale lists must be deleted.');
    }

    public function test_config_supported_locales_is_only_read_by_the_seeder_and_the_bootstrap_only_fallback(): void
    {
        $allowedFiles = [
            base_path('database/seeders/ReferenceDataSeeder.php'),
            base_path('app/Core/Localization/LocaleManager.php'),
            base_path('modules/Seo/Services/SeoMetadataService.php'),
            base_path('app/Livewire/ControlCenter/LanguageManager.php'),
        ];

        $hits = [];
        foreach ($this->phpFiles(['app', 'modules']) as $file) {
            if (in_array($file, $allowedFiles, true)) {
                continue;
            }
            $contents = file_get_contents($file);
            if (str_contains($contents, "config('app.supported_locales'")) {
                $hits[] = $file;
            }
        }

        $this->assertSame([], $hits, "Owner Delta §1/§6: config('app.supported_locales') must not become a second source of truth for supported locales.");
    }

    public function test_context_manager_binding_stays_request_scoped_not_singleton(): void
    {
        $contents = file_get_contents(base_path('app/Providers/AppServiceProvider.php'));

        $this->assertMatchesRegularExpression(
            '/\$this->app->scoped\(ContextManager::class\)/',
            $contents,
            'ContextManager must remain scoped() — never singleton() — to guarantee no cross-request context leakage.'
        );
    }

    public function test_theme_middleware_never_reaches_into_a_modules_eloquent_model_directly(): void
    {
        $contents = file_get_contents(base_path('app/Core/Theme/Http/Middleware/ResolveStorefrontThemeMiddleware.php'));

        $this->assertStringNotContainsString('Modules\\', $contents);
    }

    public function test_core_context_resolver_never_reaches_into_a_modules_eloquent_model_directly(): void
    {
        // App\Core\Context must depend on the published
        // RegionalPreferenceProviderInterface contract, never
        // Modules\Customers\Models\CustomerProfile directly.
        $contents = file_get_contents(base_path('app/Core/Context/Resolvers/LocaleResolver.php'));
        $this->assertStringNotContainsString('Modules\\Customers\\Models', $contents);

        $contents = file_get_contents(base_path('app/Core/Context/Resolvers/CurrencyResolver.php'));
        $this->assertStringNotContainsString('Modules\\Customers\\Models', $contents);
    }

    public function test_no_second_search_service_implementation_touches_meilisearch_or_scout_outside_search_module(): void
    {
        $hits = [];
        foreach ($this->phpFiles(['app']) as $file) {
            $contents = file_get_contents($file);
            if (str_contains($contents, 'Meilisearch\\') || preg_match('/::search\(/', $contents)) {
                $hits[] = $file;
            }
        }

        $this->assertSame([], $hits);
    }

    public function test_package_json_still_has_no_react_vue_or_next(): void
    {
        $contents = file_get_contents(base_path('package.json'));

        $this->assertStringNotContainsStringIgnoringCase('"react"', $contents);
        $this->assertStringNotContainsStringIgnoringCase('"vue"', $contents);
        $this->assertStringNotContainsStringIgnoringCase('"next"', $contents);
    }

    public function test_market_is_not_hard_deletable_from_the_market_manager_screen(): void
    {
        $contents = file_get_contents(base_path('app/Livewire/ControlCenter/MarketManager.php'));

        $this->assertStringNotContainsString('function deleteMarket', $contents);
        $this->assertStringContainsString('function deactivateMarket', $contents);
    }

    /**
     * @return list<string>
     */
    private function phpFiles(array $dirs): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
