<?php

declare(strict_types=1);

namespace App\Core\Modular;

use App\Core\Modular\Contracts\ModuleInterface;
use App\Core\Modular\DTOs\ModuleManifest;
use Illuminate\Support\ServiceProvider;

/**
 * Abstract base class for all Module Service Providers.
 *
 * Each module must provide its own ServiceProvider that extends this class.
 * The ModuleKernel uses this class to register and boot modules.
 */
abstract class ModuleServiceProvider extends ServiceProvider implements ModuleInterface
{
    protected ModuleManifest $manifest;

    /**
     * Return the absolute path to this module's root directory.
     * Typically: base_path('modules/ModuleName') or the fixture path in tests.
     */
    abstract public function getPath(): string;

    public function getName(): string
    {
        return $this->getManifest()->name;
    }

    public function getNamespace(): string
    {
        return $this->getManifest()->namespace;
    }

    /**
     * @return array<int, string>
     */
    public function getDependencies(): array
    {
        return $this->getManifest()->dependencies;
    }

    public function isEnabled(): bool
    {
        return $this->getManifest()->enabled;
    }

    public function getManifest(): ModuleManifest
    {
        if (! isset($this->manifest)) {
            $this->manifest = $this->loadManifest();
        }

        return $this->manifest;
    }

    protected function loadManifest(): ModuleManifest
    {
        $manifestPath = $this->getPath().'/module.json';

        if (! file_exists($manifestPath)) {
            throw new \RuntimeException(
                "Module manifest not found at [{$manifestPath}]. Each module must have a module.json file."
            );
        }

        $raw = file_get_contents($manifestPath);

        if ($raw === false) {
            throw new \RuntimeException("Unable to read module manifest at [{$manifestPath}].");
        }

        $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new \RuntimeException("Invalid module manifest format at [{$manifestPath}].");
        }

        return ModuleManifest::fromArray($data);
    }

    /**
     * Called by ModuleKernel during module registration phase.
     * Override to bind interfaces, register config, etc.
     */
    public function register(): void {}

    /**
     * Called by ModuleKernel during module boot phase.
     * Override to load routes, views, translations, etc.
     */
    public function boot(): void {}
}
