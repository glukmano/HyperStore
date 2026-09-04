<?php

declare(strict_types=1);

namespace Modules\Shipping;

use App\Core\Modular\ModuleServiceProvider;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use Livewire\Livewire;
use Modules\Shipping\Calculators\CarrierCalculatedRateCalculator;
use Modules\Shipping\Calculators\FlatRateCalculator;
use Modules\Shipping\Calculators\FreeShippingCalculator;
use Modules\Shipping\Calculators\LocalDeliveryCalculator;
use Modules\Shipping\Calculators\LocalPickupCalculator;
use Modules\Shipping\Calculators\TableRateCalculator;
use Modules\Shipping\Calculators\WeightRateCalculator;
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
use Modules\Shipping\Services\CarrierCredentialService;
use Modules\Shipping\Services\ShippingRateEngine;
use Modules\Shipping\Services\ShippingRestrictionEvaluator;
use Modules\Shipping\Services\ShippingZoneMatcher;
use Modules\Shipping\TableRate\TableRateActionRegistry;
use Modules\Shipping\TableRate\TableRateConditionRegistry;

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
        $this->app->singleton(ShippingRestrictionEvaluator::class);
        $this->app->singleton(CarrierCredentialService::class);
        $this->app->singleton(TableRateConditionRegistry::class);
        $this->app->singleton(TableRateActionRegistry::class);
        $this->app->singleton(ShippingRateEngineInterface::class, ShippingRateEngine::class);

        $this->app->singleton(ShippingMethodTypeRegistry::class, function () {
            $registry = new ShippingMethodTypeRegistry;
            $registry->register('flat_rate', FlatRateCalculator::class);
            $registry->register('free_shipping', FreeShippingCalculator::class);
            $registry->register('weight_based', WeightRateCalculator::class);
            $registry->register('table_rate', TableRateCalculator::class);
            $registry->register('local_pickup', LocalPickupCalculator::class);
            $registry->register('local_delivery', LocalDeliveryCalculator::class);
            $registry->register('carrier_calculated', CarrierCalculatedRateCalculator::class);

            return $registry;
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
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
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

        $this->registerNavigation();
    }

    private function registerNavigation(): void
    {
        $nav = $this->app->make(NavigationRegistryInterface::class);
        $nav->register(new NavigationItem('shipping.zones', 'Shipping Zones', 'control-center.shipping.zones', 'Shipping', 'shipping.zones.view', 'tenant', '🌍', 10));
        $nav->register(new NavigationItem('shipping.methods', 'Shipping Methods', 'control-center.shipping.methods', 'Shipping', 'shipping.methods.view', 'tenant', '📦', 20));
        $nav->register(new NavigationItem('shipping.rate-rules', 'Rate Rules', 'control-center.shipping.rate-rules', 'Shipping', 'shipping.rates.view', 'tenant', '📐', 30));
        $nav->register(new NavigationItem('shipping.classes', 'Shipping Classes', 'control-center.shipping.classes', 'Shipping', 'shipping.classes.view', 'tenant', '🏷️', 40));
        $nav->register(new NavigationItem('shipping.package-types', 'Package Types', 'control-center.shipping.package-types', 'Shipping', 'shipping.package_types.view', 'tenant', '📐', 50));
        $nav->register(new NavigationItem('shipping.carriers', 'Carriers', 'control-center.shipping.carriers', 'Shipping', 'shipping.carriers.view', 'tenant', '🚛', 60));
        $nav->register(new NavigationItem('shipping.pickup-locations', 'Pickup Locations', 'control-center.shipping.pickup-locations', 'Shipping', 'shipping.pickup_locations.view', 'tenant', '📍', 70));
        $nav->register(new NavigationItem('shipping.restrictions', 'Restrictions', 'control-center.shipping.restrictions', 'Shipping', 'shipping.restrictions.view', 'tenant', '🚫', 80));
        $nav->register(new NavigationItem('shipping.rate-preview', 'Rate Preview', 'control-center.shipping.rate-preview', 'Shipping', 'shipping.preview', 'tenant', '🔍', 90));
    }
}
