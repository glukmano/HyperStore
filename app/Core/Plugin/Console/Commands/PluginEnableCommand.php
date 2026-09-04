<?php

declare(strict_types=1);

namespace App\Core\Plugin\Console\Commands;

use App\Core\Plugin\Services\PluginLifecycleService;
use Illuminate\Console\Command;
use Throwable;

class PluginEnableCommand extends Command
{
    protected $signature = 'plugin:enable {plugin_id} {--approve-permissions : Explicitly approve any newly requested capabilities before enabling}';

    protected $description = 'Enable an installed plugin (runs its migrations, registers it into the platform).';

    public function handle(PluginLifecycleService $lifecycle): int
    {
        $pluginId = (string) $this->argument('plugin_id');

        try {
            $plugin = $lifecycle->enable($pluginId, (bool) $this->option('approve-permissions'));
        } catch (Throwable $e) {
            $this->error('Enable failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Plugin [{$plugin->plugin_id}] enabled (version {$plugin->version}).");

        return self::SUCCESS;
    }
}
