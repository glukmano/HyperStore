<?php

declare(strict_types=1);

namespace Modules\Fulfillment;

use App\Core\Modular\ModuleServiceProvider;
use Livewire\Livewire;
use Modules\Fulfillment\Contracts\FulfillmentPlanningServiceInterface;
use Modules\Fulfillment\Contracts\PackingStrategyInterface;
use Modules\Fulfillment\Livewire\FulfillmentPreviewTool;
use Modules\Fulfillment\Livewire\FulfillmentSourceManager;
use Modules\Fulfillment\Livewire\FulfillmentStrategyManager;
use Modules\Fulfillment\Services\DefaultPackingService;
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
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadRoutesFrom(__DIR__.'/Routes/api.php');
        $this->loadViewsFrom(__DIR__.'/Resources/views', 'fulfillment');

        if (class_exists(Livewire::class)) {
            Livewire::component('fulfillment.source-manager', FulfillmentSourceManager::class);
            Livewire::component('fulfillment.strategy-manager', FulfillmentStrategyManager::class);
            Livewire::component('fulfillment.preview-tool', FulfillmentPreviewTool::class);
        }
    }
}
