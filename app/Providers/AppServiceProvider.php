<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Audit\AuditManager;
use App\Core\Audit\Contracts\AuditManagerInterface;
use App\Core\Channels\Contracts\StoreChannelEligibilityInterface;
use App\Core\Channels\Services\StoreChannelEligibilityService;
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
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use App\Core\Navigation\NavigationRegistry;
use App\Core\Routing\DomainAddressingService;
use App\Core\Theme\Contracts\ThemeRegistryInterface;
use App\Core\Theme\Contracts\ThemeResolverInterface;
use App\Core\Theme\DTOs\ThemeManifest;
use App\Core\Theme\ThemeRegistry;
use App\Core\Theme\ThemeResolver;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StoreChannelEligibilityInterface::class, StoreChannelEligibilityService::class);
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
        $this->app->singleton(NavigationRegistryInterface::class, NavigationRegistry::class);
        $this->app->singleton(ThemeRegistryInterface::class, ThemeRegistry::class);
        $this->app->singleton(ThemeResolverInterface::class, ThemeResolver::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ModuleListCommand::class,
            ]);
        }

        $navigation = $this->app->make(NavigationRegistryInterface::class);
        $navigation->register(new NavigationItem(
            key: 'dashboard',
            label: 'Dashboard',
            routeName: 'control-center.dashboard',
            group: 'Overview',
            context: 'all',
            icon: '🏠',
            order: 0,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-stores',
            label: 'Stores',
            routeName: 'control-center.platform.stores',
            group: 'Platform',
            permission: 'stores.view',
            context: 'tenant',
            icon: '🏬',
            order: 10,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-markets',
            label: 'Markets',
            routeName: 'control-center.platform.markets',
            group: 'Platform',
            permission: 'markets.view',
            context: 'tenant',
            icon: '🌍',
            order: 20,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-channels',
            label: 'Channels',
            routeName: 'control-center.platform.channels',
            group: 'Platform',
            permission: 'channels.view',
            context: 'tenant',
            icon: '📡',
            order: 30,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-settings',
            label: 'Settings',
            routeName: 'control-center.platform.settings',
            group: 'Platform',
            permission: 'settings.manage',
            context: 'tenant',
            icon: '⚙️',
            order: 40,
        ));

        $navigation->register(new NavigationItem(
            key: 'platform-users',
            label: 'Users & Roles',
            routeName: 'control-center.platform.users',
            group: 'Platform',
            permission: 'users.view',
            context: 'tenant',
            icon: '👥',
            order: 50,
        ));

        $this->app->make(ThemeRegistryInterface::class)->register(
            ThemeManifest::fromJsonFile(base_path('themes/default/theme.json'))
        );
        // Unconditional boot-time default so `theme::` resolves outside storefront
        // requests too (console, static analysis); ResolveStorefrontThemeMiddleware
        // replaces this with the Store-resolved chain per-request.
        View::addNamespace('theme', base_path('themes/default'));

        $kernel = $this->app->make(ModuleKernelInterface::class);
        $kernel->discover();
        $kernel->registerModules();
        $kernel->bootModules();
    }
}
