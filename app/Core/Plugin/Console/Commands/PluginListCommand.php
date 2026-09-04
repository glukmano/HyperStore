<?php

declare(strict_types=1);

namespace App\Core\Plugin\Console\Commands;

use App\Core\Plugin\Models\Plugin;
use Illuminate\Console\Command;

class PluginListCommand extends Command
{
    protected $signature = 'plugin:list';

    protected $description = 'List all installed plugins and their lifecycle state.';

    public function handle(): int
    {
        $plugins = Plugin::query()->orderBy('plugin_id')->get();

        if ($plugins->isEmpty()) {
            $this->info('No plugins installed.');

            return self::SUCCESS;
        }

        $this->table(
            ['Plugin ID', 'Name', 'Version', 'Status', 'Trust', 'Failures'],
            $plugins->map(fn (Plugin $p): array => [
                $p->plugin_id, $p->name, $p->version, $p->status, $p->trust_level, $p->consecutive_boot_failures,
            ])->all()
        );

        return self::SUCCESS;
    }
}
