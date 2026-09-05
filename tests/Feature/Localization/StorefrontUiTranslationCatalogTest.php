<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use Tests\TestCase;

/**
 * Phase-18 Final Completion Delta §4: real first-party UI string catalogs
 * must exist and be loaded — ThemeTranslationResolver's fixture-proven
 * fallback chain alone is not sufficient if there is no actual catalog
 * behind it for the storefront's existing __() calls. lang/ar.json
 * translates every literal-string __() call already used throughout
 * themes/default (Laravel's built-in JSON-catalog convention — the
 * correct mechanism for "translate the literal string a __() call
 * already uses", distinct from ThemeTranslationResolver's group-file
 * seam for theme-specific key overrides).
 */
class StorefrontUiTranslationCatalogTest extends TestCase
{
    public function test_the_arabic_catalog_file_exists_and_is_valid_json(): void
    {
        $path = base_path('lang/ar.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded);
        $this->assertNotEmpty($decoded);
    }

    public function test_a_real_storefront_string_is_actually_translated_when_the_locale_is_arabic(): void
    {
        app()->setLocale('ar');

        $this->assertSame('أضف إلى السلة', __('Add to Cart'));
        $this->assertSame('سلتك فارغة.', __('Your cart is empty.'));
        $this->assertSame('قارن', __('Compare'));
        $this->assertSame('قائمة الرغبات', __('Wishlist'));
    }

    public function test_an_untranslated_key_safely_falls_back_to_the_literal_string_not_an_exception(): void
    {
        app()->setLocale('ar');

        $this->assertSame('This literal string was never added to any catalog', __('This literal string was never added to any catalog'));
    }

    public function test_every_literal_storefront_string_used_in_theme_blade_files_has_an_arabic_catalog_entry(): void
    {
        $catalog = json_decode((string) file_get_contents(base_path('lang/ar.json')), true);

        $missing = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('themes/default'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if (preg_match_all("/__\('((?:[^'\\\\]|\\\\.)*)'\)/", $contents, $matches)) {
                foreach ($matches[1] as $literal) {
                    $literal = str_replace("\\'", "'", $literal);
                    if (! array_key_exists($literal, $catalog)) {
                        $missing[] = $literal;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'Every literal __() string in themes/default must have a real Arabic catalog entry.');
    }
}
