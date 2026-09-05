<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Core\Theme\DTOs\ResolvedTheme;
use App\Core\Theme\Services\ThemeTranslationResolver;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Phase-18 Owner Delta §13: theme translation fallback must be a proven,
 * deterministic resolver — not an assumption about Laravel's namespaced
 * loadTranslationsFrom() hint-merging behavior. Exercised against fixture
 * theme directories (matching ThemeResolver's own established precedent
 * of testing against fixtures, not a second real shipped theme).
 */
class ThemeTranslationResolverTest extends TestCase
{
    private string $fixturesRoot;

    private ThemeTranslationResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturesRoot = storage_path('framework/testing/theme-translation-fixtures-'.uniqid());
        $this->resolver = new ThemeTranslationResolver;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixturesRoot);
        parent::tearDown();
    }

    private function makeLangFile(string $theme, string $locale, array $data): void
    {
        $dir = "{$this->fixturesRoot}/{$theme}/resources/lang/{$locale}";
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/theme.php", '<?php return '.var_export($data, true).';');
    }

    private function chainOf(array $themeNames): ResolvedTheme
    {
        $viewPaths = array_map(fn (string $name) => "{$this->fixturesRoot}/{$name}", $themeNames);

        return new ResolvedTheme(activeThemeName: $themeNames[0], chain: $themeNames, viewPaths: $viewPaths);
    }

    public function test_child_theme_key_wins_over_parent(): void
    {
        $this->makeLangFile('child', 'en', ['welcome' => 'Hello from child']);
        $this->makeLangFile('default', 'en', ['welcome' => 'Hello from default']);

        $result = $this->resolver->resolve('welcome', $this->chainOf(['child', 'default']), 'en');

        $this->assertSame('Hello from child', $result);
    }

    public function test_missing_in_child_falls_back_to_parent(): void
    {
        $this->makeLangFile('child', 'en', ['other_key' => 'irrelevant']);
        $this->makeLangFile('default', 'en', ['welcome' => 'Hello from default']);

        $result = $this->resolver->resolve('welcome', $this->chainOf(['child', 'default']), 'en');

        $this->assertSame('Hello from default', $result);
    }

    public function test_missing_in_every_theme_level_falls_back_to_platform_lang_file(): void
    {
        $this->makeLangFile('child', 'en', ['other_key' => 'irrelevant']);
        $this->makeLangFile('default', 'en', ['another_key' => 'irrelevant']);

        // lang/en/theme.php (the real platform catalog) defines 'add_to_cart'.
        $result = $this->resolver->resolve('add_to_cart', $this->chainOf(['child', 'default']), 'en');

        $this->assertSame('Add to Cart', $result);
    }

    public function test_missing_everywhere_returns_null_never_throws(): void
    {
        $this->makeLangFile('child', 'en', []);

        $result = $this->resolver->resolve('genuinely_nowhere_key', $this->chainOf(['child', 'default']), 'en');

        $this->assertNull($result);
    }

    public function test_locale_specific_platform_fallback_is_used(): void
    {
        $result = $this->resolver->resolve('add_to_cart', $this->chainOf(['child', 'default']), 'ar');

        $this->assertSame('أضف إلى السلة', $result);
    }

    public function test_a_theme_with_no_lang_directory_at_all_safely_falls_through(): void
    {
        // 'child' has no resources/lang directory whatsoever.
        $result = $this->resolver->resolve('add_to_cart', $this->chainOf(['child', 'default']), 'en');

        $this->assertSame('Add to Cart', $result);
    }
}
