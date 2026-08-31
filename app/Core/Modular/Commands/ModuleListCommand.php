<?php

declare(strict_types=1);

namespace App\Core\Modular\Commands;

use App\Core\Modular\Contracts\ModuleKernelInterface;
use Illuminate\Console\Command;

/**
 * Artisan command: php artisan module:list
 *
 * Lists all registered modules with their status, namespace, dependencies, and version.
 */
class ModuleListCommand extends Command
{
    protected $signature = 'module:list';

    protected $description = 'List all registered modules and their status.';

    public function handle(ModuleKernelInterface $kernel): int
    {
        $kernel->discover();

        $registry = $kernel->getRegistry();
        $all = $registry->all();

        if ($all === []) {
            $this->info('No modules discovered.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($all as $module) {
            $rows[] = [
                $module->getName(),
                $module->isEnabled() ? '<fg=green>enabled</>' : '<fg=red>disabled</>',
                $module->getNamespace(),
                implode(', ', $module->getDependencies()) ?: '—',
            ];
        }

        $this->table(
            headers: ['Name', 'Status', 'Namespace', 'Dependencies'],
            rows: $rows,
        );

        $total = count($all);
        $enabled = count($registry->enabled());
        $this->line('');
        $this->info("Total: {$total} | Enabled: {$enabled} | Disabled: ".($total - $enabled));

        return self::SUCCESS;
    }
}
