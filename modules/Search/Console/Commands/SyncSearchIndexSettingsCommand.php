<?php

declare(strict_types=1);

namespace Modules\Search\Console\Commands;

use Illuminate\Console\Command;
use Modules\Search\Services\SearchIndexSettingsSyncService;

/**
 * `php artisan search:sync-index-settings` — the one command an operator
 * runs after adding a new active Locale (or disabling one) to make
 * Meilisearch's searchable/filterable attributes match it, with zero
 * application code change (Phase-18 Final Completion Delta §6(B)).
 */
final class SyncSearchIndexSettingsCommand extends Command
{
    protected $signature = 'search:sync-index-settings';

    protected $description = 'Sync Meilisearch searchable/filterable attributes from the currently active Locales.';

    public function handle(SearchIndexSettingsSyncService $service): int
    {
        if (config('scout.driver') !== 'meilisearch') {
            $this->components->info('Scout driver is not "meilisearch" — nothing to sync.');

            return self::SUCCESS;
        }

        foreach ($service->indexNames() as $index) {
            $service->syncIndex($index);
            $this->components->info("Synced index settings for [{$index}].");
        }

        return self::SUCCESS;
    }
}
