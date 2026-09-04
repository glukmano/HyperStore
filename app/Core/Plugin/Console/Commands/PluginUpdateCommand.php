<?php

declare(strict_types=1);

namespace App\Core\Plugin\Console\Commands;

use App\Core\Plugin\Services\PluginLifecycleService;
use Illuminate\Console\Command;
use Throwable;

class PluginUpdateCommand extends Command
{
    protected $signature = 'plugin:update {plugin_id} {zip_path}';

    protected $description = 'Update an installed plugin to a new package version (atomic: old code stays live until the new one is fully validated and migrated).';

    public function handle(PluginLifecycleService $lifecycle): int
    {
        $pluginId = (string) $this->argument('plugin_id');
        $zipPath = (string) $this->argument('zip_path');

        try {
            $plugin = $lifecycle->update($pluginId, $zipPath);
        } catch (Throwable $e) {
            $this->error('Update failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Plugin [{$plugin->plugin_id}] updated to version {$plugin->version}.");

        return self::SUCCESS;
    }
}
