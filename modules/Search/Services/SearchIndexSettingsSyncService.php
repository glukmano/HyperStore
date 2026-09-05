<?php

declare(strict_types=1);

namespace Modules\Search\Services;

use App\Core\ReferenceData\Models\Language;
use Meilisearch\Client;

/**
 * Phase-18 Final Completion Delta §6(B): the Meilisearch searchable-
 * attributes list for every locale-bearing index is generated from
 * currently-active `languages` rows — never a static en/ar (or any other
 * fixed pair) list. Adding a new active Locale + running the existing
 * `search:sync-index-settings` command is the entire path to making it
 * searchable, with zero application code change.
 */
final class SearchIndexSettingsSyncService
{
    /**
     * @var array<string, list<string>>
     */
    private const array LOCALE_FIELD_PREFIXES = [
        'products' => ['name', 'description'],
        'categories' => ['name'],
        'cms_pages' => ['title', 'slug'],
        'blog_posts' => ['title', 'excerpt', 'slug'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array LOCALE_NEUTRAL_ATTRIBUTES = [
        'products' => ['sku', 'product_type', 'brand_id', 'category_ids', 'store_ids', 'is_featured', 'tenant_id'],
        'categories' => ['store_ids', 'tenant_id'],
        'cms_pages' => ['tenant_id'],
        'blog_posts' => ['tenant_id'],
        'vendors' => ['name', 'platform_slug', 'tenant_id'],
    ];

    /**
     * @return list<non-empty-string>
     */
    public function searchableAttributesFor(string $index): array
    {
        $prefixes = self::LOCALE_FIELD_PREFIXES[$index] ?? [];
        $neutral = self::LOCALE_NEUTRAL_ATTRIBUTES[$index] ?? [];

        if ($prefixes === []) {
            return $neutral;
        }

        $activeLocales = Language::query()->where('is_active', true)->pluck('code');

        $attributes = $neutral;
        foreach ($activeLocales as $locale) {
            foreach ($prefixes as $prefix) {
                $attributes[] = $prefix.'_'.$locale;
            }
        }

        return $attributes;
    }

    /**
     * @return list<non-empty-string>
     */
    public function filterableAttributesFor(string $index): array
    {
        return self::LOCALE_NEUTRAL_ATTRIBUTES[$index] ?? [];
    }

    /**
     * @return list<string>
     */
    public function indexNames(): array
    {
        return [...array_keys(self::LOCALE_FIELD_PREFIXES), 'vendors'];
    }

    /**
     * No-ops safely when Scout isn't configured for the real Meilisearch
     * driver (e.g. the `collection`/`database` driver used by the default
     * test suite) — this service's settings computation is fully testable
     * without a live daemon; only this method needs one.
     */
    public function syncIndex(string $index): void
    {
        if (config('scout.driver') !== 'meilisearch') {
            return;
        }

        $client = $this->client();
        $client->index($index)->updateSearchableAttributes($this->searchableAttributesFor($index));
        $client->index($index)->updateFilterableAttributes($this->filterableAttributesFor($index));
    }

    /**
     * Constructed directly (never bound in the app container) so the only
     * place `Meilisearch\Client` is referenced by name stays inside
     * modules/Search/ — SearchServiceInterface/ScoutSearchService remain
     * the sole authoritative Search path platform-wide.
     */
    private function client(): Client
    {
        return new Client(
            (string) config('scout.meilisearch.host', 'http://localhost:7700'),
            config('scout.meilisearch.key'),
        );
    }

    public function syncAll(): void
    {
        foreach ($this->indexNames() as $index) {
            $this->syncIndex($index);
        }
    }
}
