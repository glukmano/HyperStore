<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Audit\AuditManager;
use App\Core\Audit\Contracts\AuditManagerInterface;
use App\Core\Context\ContextManager;
use App\Core\Features\Contracts\FeatureManagerInterface;
use App\Core\Features\FeatureManager;
use App\Core\Localization\Contracts\LocaleManagerInterface;
use App\Core\Localization\LocaleManager;
use App\Core\Modular\Commands\ModuleListCommand;
use App\Core\Modular\Contracts\ModuleKernelInterface;
use App\Core\Modular\Contracts\ModuleRegistryInterface;
use App\Core\Modular\ModuleKernel;
use App\Core\Modular\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ── Modular Kernel ─────────────────────────────────────────────────────
        $this->app->singleton(ModuleRegistryInterface::class, ModuleRegistry::class);

        $this->app->singleton(ModuleKernelInterface::class, function ($app) {
            return new ModuleKernel(
                app: $app,
                registry: $app->make(ModuleRegistryInterface::class),
                modulesBasePath: base_path('modules'),
            );
        });

        // ── Context Manager (scoped per request) ───────────────────────────────
        $this->app->scoped(ContextManager::class);

        // ── Locale Manager ─────────────────────────────────────────────────────
        $this->app->singleton(LocaleManagerInterface::class, LocaleManager::class);

        // ── Feature Flags ──────────────────────────────────────────────────────
        $this->app->singleton(FeatureManagerInterface::class, FeatureManager::class);

        // ── Audit ──────────────────────────────────────────────────────────────
        $this->app->singleton(AuditManagerInterface::class, AuditManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register module:list artisan command
        if ($this->app->runningInConsole()) {
            $this->commands([
                ModuleListCommand::class,
            ]);
        }

        // Discover and boot production modules from modules/
        $kernel = $this->app->make(ModuleKernelInterface::class);
        $kernel->discover();
        $kernel->registerModules();
        $kernel->bootModules();
    }
}
