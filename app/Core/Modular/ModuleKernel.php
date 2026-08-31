<?php

declare(strict_types=1);

namespace App\Core\Modular;

use App\Core\Modular\Contracts\ModuleKernelInterface;
use App\Core\Modular\Contracts\ModuleRegistryInterface;
use Illuminate\Contracts\Foundation\Application;

/**
 * ModuleKernel: Orchestrates the full lifecycle of project modules.
 *
 * Lifecycle stages:
 *   1. discover() — scans modules/ directory for module.json manifests, instantiates providers
 *   2. registerModules() — calls register() on each enabled module in dependency order
 *   3. bootModules() — calls boot() on each enabled module in dependency order
 *
 * The kernel is intentionally simple and explicit. It does not auto-wire or
 * use reflection magic. Each module must declare a ServiceProvider class in its manifest.
 *
 * This is a project-owned implementation. Do NOT replace with nwidart/laravel-modules.
 */
final class ModuleKernel implements ModuleKernelInterface
{
    private bool $discovered = false;

    private bool $registered = false;

    public function __construct(
        private readonly Application $app,
        private readonly ModuleRegistryInterface $registry,
        private readonly string $modulesBasePath,
    ) {}

    /**
     * Scan the modules base directory for module.json manifests.
     * Instantiates the declared ServiceProvider for each module.
     */
    public function discover(): void
    {
        if ($this->discovered) {
            return;
        }

        $this->discovered = true;

        if (! is_dir($this->modulesBasePath)) {
            return;
        }

        $iterator = new \DirectoryIterator($this->modulesBasePath);

        foreach ($iterator as $entry) {
            if ($entry->isDot() || ! $entry->isDir()) {
                continue;
            }

            $manifestPath = $entry->getPathname().'/module.json';

            if (! file_exists($manifestPath)) {
                continue;
            }

            $this->loadModuleFromPath($entry->getPathname());
        }
    }

    /**
     * Additional method to discover a module at a custom path (for testing).
     */
    public function discoverAt(string $path): void
    {
        if (! is_dir($path)) {
            throw new \InvalidArgumentException("Module path not found: [{$path}].");
        }

        if (! file_exists($path.'/module.json')) {
            throw new \InvalidArgumentException("No module.json found at: [{$path}].");
        }

        $this->loadModuleFromPath($path);
    }

    public function registerModules(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        foreach ($this->registry->getOrdered() as $module) {
            $module->register();
        }
    }

    public function bootModules(): void
    {
        foreach ($this->registry->getOrdered() as $module) {
            $module->boot();
        }
    }

    public function getRegistry(): ModuleRegistryInterface
    {
        return $this->registry;
    }

    private function loadModuleFromPath(string $path): void
    {
        $manifestPath = $path.'/module.json';
        $raw = file_get_contents($manifestPath);

        if ($raw === false) {
            return;
        }

        try {
            $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Invalid JSON in module manifest [{$manifestPath}]: {$e->getMessage()}");
        }

        if (! is_array($data)) {
            return;
        }

        $providerClass = $data['provider'] ?? null;

        if (! is_string($providerClass) || $providerClass === '') {
            return;
        }

        if (! class_exists($providerClass)) {
            throw new \RuntimeException(
                "Module provider class [{$providerClass}] declared in [{$manifestPath}] was not found. ".
                'Ensure the class is autoloaded.'
            );
        }

        /** @var ModuleServiceProvider $provider */
        $provider = new $providerClass($this->app);

        if (! ($provider instanceof ModuleServiceProvider)) {
            throw new \RuntimeException(
                "Module provider [{$providerClass}] must extend [".ModuleServiceProvider::class.'].'
            );
        }

        $this->registry->register($provider);
    }
}
