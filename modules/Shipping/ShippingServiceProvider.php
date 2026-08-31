<?php

declare(strict_types=1);

namespace Modules\Shipping;

use App\Core\Modular\ModuleServiceProvider;
use Livewire\Livewire;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Contracts\ShippingZoneMatcherInterface;
use Modules\Shipping\Livewire\CarrierManager;
use Modules\Shipping\Livewire\PackageTypeManager;
use Modules\Shipping\Livewire\PickupLocationManager;
use Modules\Shipping\Livewire\RatePreviewTool;
use Modules\Shipping\Livewire\RateRuleManager;
use Modules\Shipping\Livewire\ShippingClassManager;
use Modules\Shipping\Livewire\ShippingMethodManager;
use Modules\Shipping\Livewire\ShippingRestrictionManager;
use Modules\Shipping\Livewire\ShippingZoneManager;
use Modules\Shipping\Providers\ManualCarrierProvider;
use Modules\Shipping\Registries\CarrierRegistry;
use Modules\Shipping\Registries\ShippingMethodTypeRegistry;
use Modules\Shipping\Services\ShippingRateEngine;
use Modules\Shipping\Services\ShippingZoneMatcher;

class ShippingServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(ShippingZoneMatcherInterface::class, ShippingZoneMatcher::class);
        $this->app->singleton(ShippingRateEngineInterface::class, ShippingRateEngine::class);

        $this->app->singleton(ShippingMethodTypeRegistry::class, function ($app) {
            return new ShippingMethodTypeRegistry($app);
        });

        $this->app->singleton(CarrierRegistry::class, function () {
            $registry = new CarrierRegistry;
            $registry->register('manual', ManualCarrierProvider::class);

            return $registry;
        });
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadRoutesFrom(__DIR__.'/Routes/api.php');
        $this->loadViewsFrom(__DIR__.'/Resources/views', 'shipping');

        if (class_exists(Livewire::class)) {
            Livewire::component('shipping.shipping-zone-manager', ShippingZoneManager::class);
            Livewire::component('shipping.shipping-method-manager', ShippingMethodManager::class);
            Livewire::component('shipping.rate-rule-manager', RateRuleManager::class);
            Livewire::component('shipping.shipping-class-manager', ShippingClassManager::class);
            Livewire::component('shipping.package-type-manager', PackageTypeManager::class);
            Livewire::component('shipping.carrier-manager', CarrierManager::class);
            Livewire::component('shipping.pickup-location-manager', PickupLocationManager::class);
            Livewire::component('shipping.shipping-restriction-manager', ShippingRestrictionManager::class);
            Livewire::component('shipping.rate-preview-tool', RatePreviewTool::class);
        }
    }
}
