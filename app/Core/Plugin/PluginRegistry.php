<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\Plugin\Contracts\PluginRegistryInterface;
use RuntimeException;

/**
 * In-memory, per-request plugin store with an independent topological sort
 * (deliberately not shared with App\Core\Modular\ModuleRegistry — ADR-0133).
 */
final class PluginRegistry implements PluginRegistryInterface
{
    /** @var array<string, PluginServiceProvider> */
    private array $plugins = [];

    /** @var array<string, bool> */
    private array $enabledIds = [];

    public function register(PluginServiceProvider $provider): void
    {
        $this->plugins[$provider->getId()] = $provider;
    }

    public function markEnabled(string $pluginId): void
    {
        $this->enabledIds[$pluginId] = true;
    }

    public function all(): array
    {
        return array_values($this->plugins);
    }

    public function isEnabled(string $pluginId): bool
    {
        return $this->enabledIds[$pluginId] ?? false;
    }

    /**
     * Kahn's algorithm topological sort over declared manifest dependencies,
     * restricted to enabled plugins only. Throws on a missing dependency or
     * a circular dependency, mirroring ModuleRegistry's proven diagnostics.
     */
    public function getOrdered(): array
    {
        $enabled = array_filter($this->plugins, fn (PluginServiceProvider $p): bool => $this->isEnabled($p->getId()));

        $inDegree = [];
        $dependents = [];
        foreach ($enabled as $id => $plugin) {
            $inDegree[$id] = 0;
            $dependents[$id] = [];
        }

        foreach ($enabled as $id => $plugin) {
            foreach (array_keys($plugin->getManifest()->dependencies) as $dependencyId) {
                if (! isset($enabled[$dependencyId])) {
                    throw new RuntimeException("Plugin [{$id}] depends on [{$dependencyId}], but that plugin is not installed or not enabled.");
                }
                $dependents[$dependencyId][] = $id;
                $inDegree[$id]++;
            }
        }

        $queue = array_keys(array_filter($inDegree, fn (int $degree): bool => $degree === 0));
        sort($queue);

        $sorted = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            $sorted[] = $current;

            $next = $dependents[$current] ?? [];
            sort($next);
            foreach ($next as $dependentId) {
                $inDegree[$dependentId]--;
                if ($inDegree[$dependentId] === 0) {
                    $queue[] = $dependentId;
                }
            }
        }

        if (count($sorted) !== count($enabled)) {
            $unresolved = array_diff(array_keys($enabled), $sorted);
            throw new RuntimeException('Circular plugin dependency detected. Unresolvable plugins: ['.implode(', ', $unresolved).'].');
        }

        return array_map(fn (string $id): PluginServiceProvider => $enabled[$id], $sorted);
    }
}
