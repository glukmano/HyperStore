<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-18 Owner Delta §1: the original varchar(10) locale columns cannot
 * hold a full BCP-47-lite tag (language-Script-REGION, e.g. "zh-Hans-CN"
 * is 10 chars exactly and leaves no room for anything longer / a stray
 * variant subtag). Every locale-bearing column across the schema is
 * widened to varchar(35) in one pass so no partial/inconsistent-width
 * graph is ever left behind. Widening a varchar column is a metadata-only
 * operation in PostgreSQL (no table rewrite, existing en/ar/de/fr/es/zh
 * rows are preserved verbatim). This raw ALTER COLUMN TYPE syntax is
 * Postgres-specific; SQLite (used by the default test suite) never
 * enforces varchar length limits anyway, so skipping it there is a no-op,
 * not a behavior gap.
 */
return new class extends Migration
{
    /** @var list<array{table: string, column: string}> */
    private array $localeColumns = [
        ['table' => 'languages', 'column' => 'code'],
        ['table' => 'countries', 'column' => 'default_locale_code'],
        ['table' => 'markets', 'column' => 'default_locale_code'],
        ['table' => 'market_languages', 'column' => 'locale_code'],
        ['table' => 'product_translations', 'column' => 'locale'],
        ['table' => 'category_translations', 'column' => 'locale'],
        ['table' => 'brand_translations', 'column' => 'locale'],
        ['table' => 'attribute_translations', 'column' => 'locale'],
        ['table' => 'attribute_option_translations', 'column' => 'locale'],
        ['table' => 'product_store_listing_translations', 'column' => 'locale'],
        ['table' => 'product_custom_field_translations', 'column' => 'locale'],
        ['table' => 'product_custom_field_option_translations', 'column' => 'locale'],
        ['table' => 'page_translations', 'column' => 'locale'],
        ['table' => 'blog_post_translations', 'column' => 'locale'],
        ['table' => 'faq_translations', 'column' => 'locale'],
        ['table' => 'menu_item_translations', 'column' => 'locale'],
        ['table' => 'banner_translations', 'column' => 'locale'],
        ['table' => 'redirects', 'column' => 'locale'],
        ['table' => 'carts', 'column' => 'locale'],
        ['table' => 'checkout_sessions', 'column' => 'locale'],
        ['table' => 'orders', 'column' => 'locale'],
        ['table' => 'search_queries', 'column' => 'locale'],
        ['table' => 'search_synonyms', 'column' => 'locale'],
        ['table' => 'users', 'column' => 'default_locale'],
    ];

    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        if ($isPgsql) {
            foreach ($this->localeColumns as $entry) {
                DB::statement("ALTER TABLE {$entry['table']} ALTER COLUMN {$entry['column']} TYPE varchar(35)");
            }
        }

        Schema::table('languages', function (Blueprint $table): void {
            $table->string('language_code', 10)->nullable();
            $table->string('fallback_locale_code', 35)->nullable();
            $table->integer('sort_order')->default(0);
        });

        if ($isPgsql) {
            DB::statement('ALTER TABLE languages ADD CONSTRAINT languages_fallback_locale_code_foreign FOREIGN KEY (fallback_locale_code) REFERENCES languages(code) ON DELETE SET NULL');
        }

        // Self-derive language_code for the existing bare-code seed rows
        // (en, ar, de, fr, es, zh) — non-destructive, no existing value changes.
        DB::table('languages')->whereNull('language_code')->update(['language_code' => DB::raw('code')]);
    }

    public function down(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        if ($isPgsql) {
            DB::statement('ALTER TABLE languages DROP CONSTRAINT IF EXISTS languages_fallback_locale_code_foreign');
        }

        Schema::table('languages', function (Blueprint $table): void {
            $table->dropColumn(['sort_order', 'fallback_locale_code', 'language_code']);
        });

        if ($isPgsql) {
            foreach ($this->localeColumns as $entry) {
                $width = match ($entry['table']) {
                    'orders' => 16,
                    default => 10,
                };
                DB::statement("ALTER TABLE {$entry['table']} ALTER COLUMN {$entry['column']} TYPE varchar({$width})");
            }
        }
    }
};
