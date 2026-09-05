<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Core\ReferenceData\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Search\Services\SearchIndexSettingsSyncService;
use Tests\TestCase;

/**
 * Phase-18 Final Completion Delta §6(B): Meilisearch searchable-attribute
 * lists are generated from currently-active `languages` rows, never a
 * static en/ar list — proven against "de-CH", a locale that was never
 * hardcoded anywhere in this codebase.
 */
class SearchIndexSettingsSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private SearchIndexSettingsSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SearchIndexSettingsSyncService;
    }

    public function test_products_index_gets_locale_suffixed_fields_for_every_active_locale(): void
    {
        Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);
        Language::create(['code' => 'de-CH', 'name' => 'German (CH)', 'native_name' => 'Deutsch (CH)', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);

        $attributes = $this->service->searchableAttributesFor('products');

        $this->assertContains('name_en', $attributes);
        $this->assertContains('description_en', $attributes);
        $this->assertContains('name_de-CH', $attributes);
        $this->assertContains('description_de-CH', $attributes);
        $this->assertContains('sku', $attributes);
    }

    public function test_an_inactive_locale_never_gets_indexed_fields(): void
    {
        Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);
        Language::create(['code' => 'zz', 'name' => 'Disabled', 'native_name' => 'Disabled', 'direction' => 'ltr', 'is_default' => false, 'is_active' => false]);

        $attributes = $this->service->searchableAttributesFor('products');

        $this->assertNotContains('name_zz', $attributes);
    }

    public function test_adding_a_brand_new_locale_changes_the_generated_list_with_zero_code_change(): void
    {
        Language::create(['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true]);

        $before = $this->service->searchableAttributesFor('cms_pages');
        $this->assertNotContains('title_sr-Latn-RS', $before);

        Language::create(['code' => 'sr-Latn-RS', 'name' => 'Serbian (Latin, Serbia)', 'native_name' => 'Srpski', 'direction' => 'ltr', 'is_default' => false, 'is_active' => true]);

        $after = $this->service->searchableAttributesFor('cms_pages');
        $this->assertContains('title_sr-Latn-RS', $after);
        $this->assertContains('slug_sr-Latn-RS', $after);
    }

    public function test_index_names_cover_every_locale_bearing_and_locale_neutral_entity(): void
    {
        $names = $this->service->indexNames();

        $this->assertContains('products', $names);
        $this->assertContains('categories', $names);
        $this->assertContains('cms_pages', $names);
        $this->assertContains('blog_posts', $names);
        $this->assertContains('vendors', $names);
    }

    public function test_sync_index_is_a_safe_no_op_when_scout_driver_is_not_meilisearch(): void
    {
        config(['scout.driver' => 'collection']);

        // Must not throw even though no Meilisearch client/daemon exists
        // in this environment.
        $this->service->syncIndex('products');
        $this->service->syncAll();

        $this->addToAssertionCount(1);
    }
}
