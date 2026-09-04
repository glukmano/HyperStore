<?php

declare(strict_types=1);

namespace App\Core\Plugin\Console\Commands;

use App\Core\Plugin\DTOs\PluginManifest;
use App\Core\Plugin\Models\Plugin;
use App\Core\Plugin\Services\PluginComposerDependencyChecker;
use Composer\Semver\Semver;
use Illuminate\Console\Command;
use Throwable;

class PluginDoctorCommand extends Command
{
    protected $signature = 'plugin:doctor';

    protected $description = 'Read-only diagnostics for every installed plugin: manifest validity, compatibility, dependency graph, Composer version conflicts.';

    public function handle(PluginComposerDependencyChecker $dependencyChecker): int
    {
        $plugins = Plugin::query()->orderBy('plugin_id')->get();
        $healthy = true;

        if ($plugins->isEmpty()) {
            $this->info('No plugins installed.');

            return self::SUCCESS;
        }

        foreach ($plugins as $plugin) {
            $this->line("<info>{$plugin->plugin_id}</info> (status: {$plugin->status})");
            $pluginHealthy = true;

            try {
                $manifest = PluginManifest::fromArray($plugin->manifest_snapshot);
            } catch (Throwable $e) {
                $this->error('  Manifest invalid: '.$e->getMessage());
                $healthy = false;

                continue;
            }

            if ($manifest->phpCompatibility !== '*' && ! Semver::satisfies(PHP_VERSION, $manifest->phpCompatibility)) {
                $this->error("  PHP incompatible: requires [{$manifest->phpCompatibility}], running [".PHP_VERSION.'].');
                $pluginHealthy = false;
            }

            $platformVersion = (string) config('plugins.platform_version', '1.0.0');
            if ($manifest->platformCompatibility !== '*' && ! Semver::satisfies($platformVersion, $manifest->platformCompatibility)) {
                $this->error("  Platform incompatible: requires [{$manifest->platformCompatibility}], running [{$platformVersion}].");
                $pluginHealthy = false;
            }

            foreach (array_keys($manifest->dependencies) as $dependencyId) {
                if (! Plugin::query()->where('plugin_id', $dependencyId)->where('status', Plugin::STATUS_ENABLED)->exists()) {
                    $this->error("  Missing/disabled dependency: [{$dependencyId}].");
                    $pluginHealthy = false;
                }
            }

            $pluginPath = base_path('plugins/'.$plugin->plugin_id);
            $conflicts = $dependencyChecker->findConflicts($pluginPath, $plugin->plugin_id);
            foreach ($conflicts as $conflict) {
                $this->error('  Composer dependency conflict: '.$conflict->describe());
                $pluginHealthy = false;
            }

            if ($plugin->isFailed()) {
                $this->error('  Status is failed: '.($plugin->failure_reason ?? 'no reason recorded'));
                $pluginHealthy = false;
            }

            if ($pluginHealthy) {
                $this->info('  OK');
            } else {
                $healthy = false;
            }
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
