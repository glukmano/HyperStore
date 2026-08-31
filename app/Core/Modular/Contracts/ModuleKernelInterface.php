<?php

declare(strict_types=1);

namespace App\Core\Modular\Contracts;

interface ModuleKernelInterface
{
    /**
     * Discover all modules from the modules base path.
     */
    public function discover(): void;

    /**
     * Register all discovered enabled modules.
     */
    public function registerModules(): void;

    /**
     * Boot all registered enabled modules.
     */
    public function bootModules(): void;

    public function getRegistry(): ModuleRegistryInterface;
}
