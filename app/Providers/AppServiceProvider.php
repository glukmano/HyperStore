<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Audit\AuditManager;
use App\Core\Audit\Contracts\AuditManagerInterface;
use App\Core\Context\ContextManager;
use App\Core\Customers\CustomerScopeService;
use App\Core\Features\Contracts\FeatureManagerInterface;
use App\Core\Features\FeatureManager;
use App\Core\Localization\Contracts\LocaleManagerInterface;
use App\Core\Localization\LocaleManager;
use App\Core\Modular\Commands\ModuleListCommand;
use App\Core\Modular\Contracts\ModuleKernelInterface;
use App\Core\Modular\Contracts\ModuleRegistryInterface;
use App\Core\Modular\ModuleKernel;
use App\Core\Modular\ModuleRegistry;
use App\Core\Routing\DomainAddressingService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistryInterface::class, ModuleRegistry::class);

        $this->app->singleton(ModuleKernelInterface::class, function ($app) {
            return new ModuleKernel(
                app: $app,
                registry: $app->make(ModuleRegistryInterface::class),
                modulesBasePath: base_path('modules'),
            );
        });

        $this->app->scoped(ContextManager::class);
        $this->app->singleton(LocaleManagerInterface::class, LocaleManager::class);
        $this->app->singleton(FeatureManagerInterface::class, FeatureManager::class);
        $this->app->singleton(AuditManagerInterface::class, AuditManager::class);
        $this->app->singleton(DomainAddressingService::class);
        $this->app->singleton(CustomerScopeService::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ModuleListCommand::class,
            ]);
        }

        $kernel = $this->app->make(ModuleKernelInterface::class);
        $kernel->discover();
        $kernel->registerModules();
        $kernel->bootModules();
    }
}
