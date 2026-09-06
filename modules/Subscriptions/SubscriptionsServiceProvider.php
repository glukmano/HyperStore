<?php

declare(strict_types=1);

namespace Modules\Subscriptions;

use App\Core\Modular\ModuleServiceProvider;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use Livewire\Livewire;
use Modules\Subscriptions\Console\Commands\ProcessSubscriptionRenewalsCommand;
use Modules\Subscriptions\Livewire\ControlCenter\SubscriptionManager;
use Modules\Subscriptions\Livewire\ControlCenter\SubscriptionPlanManager;
use Modules\Subscriptions\Livewire\Storefront\SubscriptionsPage;

class SubscriptionsServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function boot(): void
    {
        parent::boot();

        $webRoutesPath = __DIR__.'/Routes/web.php';
        if (file_exists($webRoutesPath)) {
            $this->loadRoutesFrom($webRoutesPath);
        }

        $viewsDir = __DIR__.'/Resources/views';
        if (is_dir($viewsDir)) {
            $this->loadViewsFrom($viewsDir, 'subscriptions');
        }

        $this->registerLivewireComponents();
        $this->registerNavigation();

        $this->commands([
            ProcessSubscriptionRenewalsCommand::class,
        ]);
    }

    private function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('subscriptions.control-center.subscription-plan-manager', SubscriptionPlanManager::class);
        Livewire::component('subscriptions.control-center.subscription-manager', SubscriptionManager::class);
        Livewire::component('subscriptions.storefront.subscriptions-page', SubscriptionsPage::class);
    }

    private function registerNavigation(): void
    {
        $nav = $this->app->make(NavigationRegistryInterface::class);
        $nav->register(new NavigationItem('subscriptions.plans', 'Subscription Plans', 'control-center.subscriptions.plans', 'Subscriptions', 'subscriptions.plans.view', 'tenant', '🔁', 10));
        $nav->register(new NavigationItem('subscriptions.manage', 'Manage Subscriptions', 'control-center.subscriptions.manage', 'Subscriptions', 'subscriptions.manage.view', 'tenant', '📋', 20));
    }
}
