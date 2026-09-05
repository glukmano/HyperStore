<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * Explicit architecture-invariant proofs required by the Phase-17 plan §33/§40:
 * no duplicate identity system, no bypass of the verified-purchase resolver,
 * no direct Scout/Meilisearch access outside modules/Search, no hardcoded
 * two-language schema, no duplicate search/notification subsystem.
 */
class Phase17ArchitectureTest extends TestCase
{
    public function test_exactly_one_authenticatable_model_exists_no_second_identity_system(): void
    {
        $matches = [];
        exec('grep -rl "extends Authenticatable" '.escapeshellarg(base_path('app')).' '.escapeshellarg(base_path('modules')).' 2>/dev/null', $matches);

        $this->assertCount(1, $matches, 'Expected exactly one Authenticatable model (App\\Models\\User). Found: '.implode(', ', $matches));
        $this->assertStringContainsString('User.php', $matches[0]);
    }

    public function test_is_verified_purchase_is_never_assigned_outside_the_reviews_module(): void
    {
        $matches = [];
        exec('grep -rln "is_verified_purchase.*=.*true\|is_verified_purchase.*=>.*true" '.escapeshellarg(base_path('app')).' '.escapeshellarg(base_path('modules')).' 2>/dev/null | grep -v "/vendor/"', $matches);

        $outsideReviews = array_filter($matches, fn (string $file) => ! str_contains($file, 'modules/Reviews/'));

        $this->assertEmpty($outsideReviews, 'is_verified_purchase must only ever be set inside modules/Reviews/ (via VerifiedPurchaseResolver), found in: '.implode(', ', $outsideReviews));
    }

    public function test_scout_facade_and_meilisearch_client_are_only_touched_inside_the_search_module(): void
    {
        $matches = [];
        exec('grep -rl "Laravel\\\\\\\\Scout\\\\\\\\Searchable\|Meilisearch\\\\\\\\Client" '.escapeshellarg(base_path('modules')).' '.escapeshellarg(base_path('app')).' 2>/dev/null | grep -v "/vendor/"', $matches);

        // Only modules/Search/ itself, or the five indexed models (Product,
        // Category, Vendor, Page, BlogPost, via Scout's own Searchable trait
        // convention), may reference Scout/Meilisearch.
        $allowedFiles = [
            'modules/Catalog/Models/Product.php',
            'modules/Catalog/Models/Category.php',
            'modules/Marketplace/Models/Vendor.php',
            'modules/Cms/Models/Page.php',
            'modules/Cms/Models/BlogPost.php',
        ];
        $unauthorized = array_filter(
            $matches,
            function (string $file) use ($allowedFiles): bool {
                if (str_contains($file, 'modules/Search/')) {
                    return false;
                }

                foreach ($allowedFiles as $allowed) {
                    if (str_contains($file, $allowed)) {
                        return false;
                    }
                }

                return true;
            }
        );

        $this->assertEmpty($unauthorized, 'Only modules/Search/ or an indexed model (Searchable) may reference Scout/Meilisearch directly, found in: '.implode(', ', $unauthorized));
    }

    public function test_no_new_phase17_translation_table_hardcodes_a_two_language_column_pattern(): void
    {
        $migrationFiles = glob(base_path('database/migrations/2026_09_05_*.php')) ?: [];
        $this->assertNotEmpty($migrationFiles, 'Expected Phase-17 migration files to exist.');

        foreach ($migrationFiles as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                '/[\'"](title|name|body|question|answer|label|headline)_(ar|en)[\'"]/',
                $contents,
                "Migration {$file} appears to hardcode a two-language column suffix instead of using the established locale-row pattern."
            );
        }
    }

    public function test_messaging_broadcast_event_is_only_dispatched_from_the_messaging_service_after_commit(): void
    {
        $matches = [];
        exec('grep -rln "MessageSent::dispatch" '.escapeshellarg(base_path('modules/Messaging')).' 2>/dev/null', $matches);

        $this->assertCount(1, $matches, 'MessageSent should be dispatched from exactly one place.');
        $this->assertStringContainsString('MessagingService.php', $matches[0]);

        $contents = (string) file_get_contents($matches[0]);
        $this->assertStringContainsString('DB::afterCommit', $contents, 'MessageSent must be dispatched inside a DB::afterCommit() callback.');
    }

    public function test_no_second_page_builder_or_block_registry_exists_outside_cms(): void
    {
        $matches = [];
        exec('grep -rlE "^(final )?class \w*BlockTypeRegistry" '.escapeshellarg(base_path('app')).' '.escapeshellarg(base_path('modules')).' 2>/dev/null', $matches);

        $this->assertCount(1, $matches, 'Expected exactly one BlockTypeRegistry implementation. Found: '.implode(', ', $matches));
        $this->assertStringContainsString('modules/Cms/', $matches[0]);
    }

    public function test_customer_engagement_never_writes_to_pricing_or_inventory_tables_directly(): void
    {
        $matches = [];
        exec('grep -rln "Price::create\|Price::update\|StockItem::create\|StockItem::update" '.escapeshellarg(base_path('modules/Customers')).' 2>/dev/null', $matches);

        $this->assertEmpty($matches, 'modules/Customers must never write to Pricing/Inventory tables directly. Found in: '.implode(', ', $matches));
    }
}
