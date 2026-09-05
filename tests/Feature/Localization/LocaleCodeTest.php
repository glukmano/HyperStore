<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use App\Core\Localization\ValueObjects\LocaleCode;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Owner Delta §1/§17: BCP-47-lite shapes must all validate/normalize —
 * language, language-REGION, language-Script, language-Script-REGION —
 * with no architectural ceiling reintroduced.
 */
class LocaleCodeTest extends TestCase
{
    public function test_bare_language_is_valid(): void
    {
        $this->assertTrue(LocaleCode::isValid('ar'));
        $this->assertSame('ar', LocaleCode::normalize('AR'));
    }

    public function test_language_region_is_valid(): void
    {
        $this->assertTrue(LocaleCode::isValid('ar-SY'));
        $this->assertSame('ar-SY', LocaleCode::normalize('ar-sy'));
        $this->assertSame('de-CH', LocaleCode::normalize('DE-ch'));
    }

    public function test_language_script_is_valid(): void
    {
        $this->assertTrue(LocaleCode::isValid('zh-Hans'));
        $this->assertSame('zh-Hans', LocaleCode::normalize('ZH-hans'));
    }

    public function test_language_script_region_is_valid(): void
    {
        $this->assertTrue(LocaleCode::isValid('zh-Hans-CN'));
        $this->assertSame('zh-Hans-CN', LocaleCode::normalize('zh-hans-cn'));

        $this->assertTrue(LocaleCode::isValid('sr-Latn-RS'));
        $this->assertSame('sr-Latn-RS', LocaleCode::normalize('SR-LATN-rs'));
    }

    public function test_numeric_region_is_valid(): void
    {
        // UN M49 numeric area codes are a legitimate BCP-47 region form.
        $this->assertTrue(LocaleCode::isValid('es-419'));
        $this->assertSame('es-419', LocaleCode::normalize('es-419'));
    }

    public function test_malformed_tags_are_rejected(): void
    {
        $this->assertFalse(LocaleCode::isValid(''));
        $this->assertFalse(LocaleCode::isValid('a'));
        $this->assertFalse(LocaleCode::isValid('toolonglanguage'));
        $this->assertFalse(LocaleCode::isValid('ar-'));
        $this->assertFalse(LocaleCode::isValid('ar--SY'));
        $this->assertFalse(LocaleCode::isValid('ar_SY'));
        $this->assertFalse(LocaleCode::isValid('12'));
    }

    public function test_normalize_throws_on_malformed_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LocaleCode::normalize('not a locale');
    }

    public function test_language_subtag_extraction(): void
    {
        $this->assertSame('ar', LocaleCode::languageSubtag('ar-SY'));
        $this->assertSame('zh', LocaleCode::languageSubtag('zh-Hans-CN'));
        $this->assertSame('en', LocaleCode::languageSubtag('en'));
    }

    public function test_all_locale_fk_columns_accept_the_normalized_supported_length(): void
    {
        // The widest realistic normalized tag ("zh-Hans-CN" = 10 chars)
        // must comfortably fit every widened locale column (varchar(35)).
        $widest = LocaleCode::normalize('zh-hans-cn');
        $this->assertLessThanOrEqual(35, strlen($widest));
    }
}
