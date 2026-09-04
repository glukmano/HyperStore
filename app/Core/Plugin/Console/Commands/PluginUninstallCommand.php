<?php

declare(strict_types=1);

namespace App\Core\Plugin\Console\Commands;

use App\Core\Plugin\Services\PluginLifecycleService;
use Illuminate\Console\Command;
use Throwable;

class PluginUninstallCommand extends Command
{
    protected $signature = 'plugin:uninstall {plugin_id} {--purge-data : Also roll back this plugin\'s migrations, permanently deleting its data}';

    protected $description = 'Uninstall a plugin. By default its database tables/data are left intact — only --purge-data removes them.';

    public function handle(PluginLifecycleService $lifecycle): int
    {
        $pluginId = (string) $this->argument('plugin_id');
        $purge = (bool) $this->option('purge-data');

        if ($purge && ! $this->confirm("This will permanently delete plugin [{$pluginId}]'s data via a scoped migration rollback. Continue?")) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        try {
            $lifecycle->uninstall($pluginId, $purge);
        } catch (Throwable $e) {
            $this->error('Uninstall failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Plugin [{$pluginId}] uninstalled".($purge ? ' (data purged).' : ' (data preserved).'));

        return self::SUCCESS;
    }
}
