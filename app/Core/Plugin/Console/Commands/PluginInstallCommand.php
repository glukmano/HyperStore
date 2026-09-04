<?php

declare(strict_types=1);

namespace App\Core\Plugin\Console\Commands;

use App\Core\Plugin\Services\PluginLifecycleService;
use Illuminate\Console\Command;
use Throwable;

class PluginInstallCommand extends Command
{
    protected $signature = 'plugin:install {zip_path}';

    protected $description = 'Validate and install a plugin package (staged, disabled by default — use plugin:enable next).';

    public function handle(PluginLifecycleService $lifecycle): int
    {
        $zipPath = (string) $this->argument('zip_path');

        try {
            $plugin = $lifecycle->install($zipPath);
        } catch (Throwable $e) {
            $this->error('Install failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Plugin [{$plugin->plugin_id}] installed (status: {$plugin->status}, trust: {$plugin->trust_level}). Run `plugin:enable {$plugin->plugin_id}` to activate it.");

        return self::SUCCESS;
    }
}
