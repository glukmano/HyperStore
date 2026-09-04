<?php

declare(strict_types=1);

namespace App\Core\Plugin\Console\Commands;

use App\Core\Plugin\Models\Plugin;
use Illuminate\Console\Command;

class PluginInspectCommand extends Command
{
    protected $signature = 'plugin:inspect {plugin_id}';

    protected $description = 'Show full lifecycle/manifest diagnostics for one plugin.';

    public function handle(): int
    {
        $pluginId = (string) $this->argument('plugin_id');
        $plugin = Plugin::query()->where('plugin_id', $pluginId)->first();

        if ($plugin === null) {
            $this->error("Plugin [{$pluginId}] is not installed.");

            return self::FAILURE;
        }

        $this->line("<info>Plugin:</info> {$plugin->plugin_id}");
        $this->line("<info>Name:</info> {$plugin->name}");
        $this->line("<info>Version:</info> {$plugin->version}");
        $this->line("<info>Status:</info> {$plugin->status}");
        $this->line("<info>Trust level:</info> {$plugin->trust_level}");
        $this->line('<info>Consecutive boot failures:</info> '.$plugin->consecutive_boot_failures);
        $this->line('<info>Last migration batch:</info> '.($plugin->last_migration_batch ?? 'none'));
        if ($plugin->failure_reason !== null) {
            $this->line("<error>Failure reason:</error> {$plugin->failure_reason}");
        }
        $this->line('<info>Requested permissions:</info> '.implode(', ', (array) ($plugin->manifest_snapshot['requested_permissions'] ?? [])));
        $this->line('<info>Granted capabilities:</info> '.implode(', ', (array) ($plugin->granted_permissions ?? [])));
        $this->line('<info>Dependencies:</info> '.json_encode($plugin->manifest_snapshot['dependencies'] ?? [], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
