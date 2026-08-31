<?php

declare(strict_types=1);

namespace App\Core\Modular;

use App\Core\Modular\Contracts\ModuleInterface;
use App\Core\Modular\Contracts\ModuleRegistryInterface;

/**
 * In-memory registry of all discovered and registered modules.
 *
 * Responsibilities:
 *   - Store module instances keyed by name
 *   - Provide filtered views (enabled, disabled)
 *   - Return modules in topological dependency order (Kahn's algorithm)
 */
final class ModuleRegistry implements ModuleRegistryInterface
{
    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    public function register(ModuleInterface $module): void
    {
        $this->modules[$module->getName()] = $module;
    }

    public function has(string $name): bool
    {
        return isset($this->modules[$name]);
    }

    public function get(string $name): ?ModuleInterface
    {
        return $this->modules[$name] ?? null;
    }

    /**
     * @return array<string, ModuleInterface>
     */
    public function all(): array
    {
        return $this->modules;
    }

    /**
     * @return array<string, ModuleInterface>
     */
    public function enabled(): array
    {
        return array_filter($this->modules, fn (ModuleInterface $m) => $m->isEnabled());
    }

    /**
     * @return array<string, ModuleInterface>
     */
    public function disabled(): array
    {
        return array_filter($this->modules, fn (ModuleInterface $m) => ! $m->isEnabled());
    }

    /**
     * Returns enabled modules sorted by dependencies using Kahn's topological sort.
     *
     * @return array<int, ModuleInterface>
     *
     * @throws \RuntimeException when a circular dependency or missing dependency is detected.
     */
    public function getOrdered(): array
    {
        $enabled = $this->enabled();

        // Build in-degree map and adjacency list
        /** @var array<string, int> $inDegree */
        $inDegree = [];

        /** @var array<string, list<string>> $dependents */
        $dependents = [];

        foreach ($enabled as $name => $module) {
            $inDegree[$name] ??= 0;
            $dependents[$name] ??= [];

            foreach ($module->getDependencies() as $dep) {
                if (! isset($enabled[$dep])) {
                    throw new \RuntimeException(
                        "Module [{$name}] depends on [{$dep}], but that module is not registered or not enabled."
                    );
                }

                $dependents[$dep][] = $name;
                $inDegree[$name] = ($inDegree[$name] ?? 0) + 1;
            }
        }

        // Kahn's BFS
        $queue = [];
        foreach ($inDegree as $name => $degree) {
            if ($degree === 0) {
                $queue[] = $name;
            }
        }

        $sorted = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            $sorted[] = $enabled[$current];

            foreach ($dependents[$current] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        if (count($sorted) !== count($enabled)) {
            $unresolved = array_diff(array_keys($enabled), array_map(
                fn (ModuleInterface $m) => $m->getName(),
                $sorted
            ));
            throw new \RuntimeException(
                'Circular module dependency detected. Unresolvable modules: ['.implode(', ', $unresolved).'].'
            );
        }

        return $sorted;
    }
}
