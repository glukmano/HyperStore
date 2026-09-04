<?php

declare(strict_types=1);

namespace Modules\Fulfillment;

use App\Core\Modular\ModuleServiceProvider;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use Livewire\Livewire;
use Modules\Fulfillment\Contracts\FulfillmentExecutionServiceInterface;
use Modules\Fulfillment\Contracts\FulfillmentPlanningServiceInterface;
use Modules\Fulfillment\Contracts\PackingStrategyInterface;
use Modules\Fulfillment\Livewire\FulfillmentPreviewTool;
use Modules\Fulfillment\Livewire\FulfillmentSourceManager;
use Modules\Fulfillment\Livewire\FulfillmentStrategyManager;
use Modules\Fulfillment\Services\DefaultPackingService;
use Modules\Fulfillment\Services\FulfillmentExecutionService;
use Modules\Fulfillment\Services\FulfillmentPlanningService;

class FulfillmentServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(PackingStrategyInterface::class, DefaultPackingService::class);
        $this->app->singleton(FulfillmentPlanningServiceInterface::class, FulfillmentPlanningService::class);
        $this->app->singleton(FulfillmentExecutionServiceInterface::class, FulfillmentExecutionService::class);
        $this->app->singleton(FulfillmentExecutionService::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadRoutesFrom(__DIR__.'/Routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        $this->loadViewsFrom(__DIR__.'/Resources/views', 'fulfillment');

        if (class_exists(Livewire::class)) {
            Livewire::component('fulfillment.source-manager', FulfillmentSourceManager::class);
            Livewire::component('fulfillment.strategy-manager', FulfillmentStrategyManager::class);
            Livewire::component('fulfillment.preview-tool', FulfillmentPreviewTool::class);
        }

        $this->registerNavigation();
    }

    private function registerNavigation(): void
    {
        $nav = $this->app->make(NavigationRegistryInterface::class);
        $nav->register(new NavigationItem('fulfillment.sources', 'Fulfillment Sources', 'control-center.fulfillment.sources', 'Fulfillment', 'fulfillment.sources.view', 'tenant', '🚚', 10));
        $nav->register(new NavigationItem('fulfillment.strategies', 'Fulfillment Strategies', 'control-center.fulfillment.strategies', 'Fulfillment', 'fulfillment.strategies.view', 'tenant', '🧭', 20));
        $nav->register(new NavigationItem('fulfillment.preview', 'Fulfillment Preview', 'control-center.fulfillment.preview', 'Fulfillment', 'fulfillment.preview', 'tenant', '🔍', 30));
    }
}
