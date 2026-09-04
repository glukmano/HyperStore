<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\Audit\Contracts\AuditManagerInterface;
use App\Core\Plugin\Contracts\PluginRegistryInterface;
use App\Core\Plugin\DTOs\PluginManifest;
use App\Core\Plugin\Models\Plugin;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Discovers, registers, and boots enabled plugins from plugins/*\/plugin.json.
 * Runs strictly after ModuleKernel (ADR-0006, ADR-0133). Every individual
 * plugin register()/boot() call is isolated: a thrown exception is logged via
 * the existing AuditManagerInterface, the plugin's failure counter increments
 * (auto-disabling after a configured threshold), and the remaining plugins
 * still boot — one broken plugin never crashes the platform.
 */
final class PluginKernel
{
    private bool $discovered = false;

    private bool $registered = false;

    public function __construct(
        private readonly Application $app,
        private readonly PluginRegistryInterface $registry,
        private readonly string $pluginsBasePath,
        private readonly AuditManagerInterface $auditManager,
    ) {}

    public function discover(): void
    {
        if ($this->discovered) {
            return;
        }

        $this->discovered = true;

        if (! is_dir($this->pluginsBasePath)) {
            return;
        }

        // Guards the bootstrapping window before the `plugins` table itself
        // has been migrated (e.g. the very first `php artisan migrate` run).
        if (! Schema::hasTable('plugins')) {
            return;
        }

        /** @var array<string, Plugin> $enabledById */
        $enabledById = Plugin::query()->where('status', Plugin::STATUS_ENABLED)->get()->keyBy('plugin_id')->all();

        if ($enabledById === []) {
            return;
        }

        $iterator = new \DirectoryIterator($this->pluginsBasePath);

        foreach ($iterator as $entry) {
            if ($entry->isDot() || ! $entry->isDir()) {
                continue;
            }

            $pluginPath = $entry->getPathname();
            $manifestPath = $pluginPath.'/plugin.json';

            if (! file_exists($manifestPath)) {
                continue;
            }

            try {
                $manifestJson = file_get_contents($manifestPath);
                if ($manifestJson === false) {
                    continue;
                }
                $manifest = PluginManifest::fromJson($manifestJson);

                if (! isset($enabledById[$manifest->id])) {
                    continue; // not enabled — never loaded, never boots.
                }

                $this->loadPluginAutoloader($pluginPath, $manifest);

                if (! class_exists($manifest->entrypoint)) {
                    throw new \RuntimeException("Plugin entrypoint class [{$manifest->entrypoint}] was not found after loading its autoloader.");
                }

                $provider = new $manifest->entrypoint($this->app, $manifest, $pluginPath);
                if (! $provider instanceof PluginServiceProvider) {
                    throw new \RuntimeException("Plugin entrypoint [{$manifest->entrypoint}] must extend App\\Core\\Plugin\\PluginServiceProvider.");
                }

                $this->registry->register($provider);
                if ($this->registry instanceof PluginRegistry) {
                    $this->registry->markEnabled($manifest->id);
                }
            } catch (Throwable $e) {
                $this->recordBootFailure($manifest->id ?? basename($pluginPath), $e);
            }
        }
    }

    public function registerPlugins(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        foreach ($this->safeOrdered() as $provider) {
            $this->isolate($provider, fn () => $provider->register());
        }
    }

    public function bootPlugins(): void
    {
        foreach ($this->safeOrdered() as $provider) {
            $this->isolate($provider, fn () => $provider->boot());
        }
    }

    public function getRegistry(): PluginRegistryInterface
    {
        return $this->registry;
    }

    /**
     * @return list<PluginServiceProvider>
     */
    private function safeOrdered(): array
    {
        try {
            return $this->registry->getOrdered();
        } catch (Throwable $e) {
            // A dependency-graph failure (missing dependency / cycle) must not
            // crash the whole request either — log and boot nothing this cycle.
            $this->auditManager->log(
                event: 'plugin.dependency_resolution_failed',
                properties: ['error' => $e->getMessage()],
            );

            return [];
        }
    }

    private function isolate(PluginServiceProvider $provider, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            $this->recordBootFailure($provider->getId(), $e);
        }
    }

    private function loadPluginAutoloader(string $pluginPath, PluginManifest $manifest): void
    {
        $autoloadPath = $pluginPath.'/vendor/autoload.php';
        if (! file_exists($autoloadPath)) {
            throw new \RuntimeException("Plugin [{$manifest->id}] is missing its generated vendor/autoload.php.");
        }

        require_once $autoloadPath;
    }

    private function recordBootFailure(string $pluginId, Throwable $e): void
    {
        $plugin = Plugin::query()->where('plugin_id', $pluginId)->first();

        $this->auditManager->log(
            event: 'plugin.boot_failed',
            properties: ['plugin_id' => $pluginId, 'error' => $e->getMessage(), 'exception' => $e::class],
            subject: $plugin,
        );

        if ($plugin === null) {
            return;
        }

        $plugin->consecutive_boot_failures++;
        $plugin->failure_reason = $e->getMessage();

        $threshold = (int) config('plugins.max_consecutive_boot_failures', 3);
        if ($plugin->consecutive_boot_failures >= $threshold) {
            $plugin->status = Plugin::STATUS_DISABLED;
            $plugin->disabled_at = now();
        }

        $plugin->save();
    }
}
