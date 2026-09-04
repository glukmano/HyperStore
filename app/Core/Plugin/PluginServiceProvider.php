<?php

declare(strict_types=1);

namespace App\Core\Plugin;

use App\Core\Plugin\DTOs\PluginManifest;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Abstract base every plugin's entrypoint class must extend.
 *
 * Deliberately extends Laravel's own base ServiceProvider directly (not
 * App\Core\Modular\ModuleServiceProvider) — Plugin and Module identity/
 * lifecycle are kept structurally independent (ADR-0133) while still
 * inheriting loadRoutesFrom()/loadViewsFrom()/loadMigrationsFrom()/
 * loadTranslationsFrom() for free.
 *
 * boot() must be safe to call once per request lifecycle (it reruns on
 * every request — see ADR-0133's per-request rebuild invariant) and must
 * never assume it has not already run in a prior request.
 */
abstract class PluginServiceProvider extends ServiceProvider
{
    public function __construct(
        Application $app,
        protected readonly PluginManifest $manifest,
        protected readonly string $pluginPath,
    ) {
        parent::__construct($app);
    }

    public function getManifest(): PluginManifest
    {
        return $this->manifest;
    }

    public function getId(): string
    {
        return $this->manifest->id;
    }

    public function getPath(): string
    {
        return $this->pluginPath;
    }

    public function register(): void {}

    public function boot(): void {}
}
