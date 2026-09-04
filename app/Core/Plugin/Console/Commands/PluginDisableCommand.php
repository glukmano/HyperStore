<?php

declare(strict_types=1);

namespace App\Core\Plugin\Console\Commands;

use App\Core\Plugin\Services\PluginLifecycleService;
use Illuminate\Console\Command;
use Throwable;

class PluginDisableCommand extends Command
{
    protected $signature = 'plugin:disable {plugin_id}';

    protected $description = 'Disable a plugin. Never touches its data or files — works even against a failed plugin (CLI recovery path).';

    public function handle(PluginLifecycleService $lifecycle): int
    {
        $pluginId = (string) $this->argument('plugin_id');

        try {
            $plugin = $lifecycle->disable($pluginId);
        } catch (Throwable $e) {
            $this->error('Disable failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Plugin [{$plugin->plugin_id}] disabled.");

        return self::SUCCESS;
    }
}
