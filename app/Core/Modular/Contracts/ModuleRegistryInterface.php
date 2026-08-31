<?php

declare(strict_types=1);

namespace App\Core\Modular\Contracts;

interface ModuleRegistryInterface
{
    public function register(ModuleInterface $module): void;

    public function has(string $name): bool;

    public function get(string $name): ?ModuleInterface;

    /**
     * @return array<string, ModuleInterface>
     */
    public function all(): array;

    /**
     * @return array<string, ModuleInterface>
     */
    public function enabled(): array;

    /**
     * @return array<string, ModuleInterface>
     */
    public function disabled(): array;

    /**
     * Returns enabled modules sorted by dependencies (topological order).
     *
     * @return array<int, ModuleInterface>
     */
    public function getOrdered(): array;
}
