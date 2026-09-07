<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery;

use App\Core\Modular\ModuleServiceProvider;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\DigitalDelivery\Contracts\CustomerEntitlementServiceInterface;
use Modules\DigitalDelivery\Contracts\DigitalEntitlementGrantHookInterface;
use Modules\DigitalDelivery\Listeners\GrantEntitlementsOnPaymentCaptured;
use Modules\DigitalDelivery\Livewire\ControlCenter\DigitalAssetManager;
use Modules\DigitalDelivery\Livewire\ControlCenter\LicenseKeyPoolManager;
use Modules\DigitalDelivery\Livewire\Storefront\DigitalDownloadsPage;
use Modules\DigitalDelivery\Services\CustomerEntitlementService;
use Modules\DigitalDelivery\Services\DigitalEntitlementGrantHook;
use Modules\Payment\Events\PaymentCaptured;

class DigitalDeliveryServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(CustomerEntitlementServiceInterface::class, CustomerEntitlementService::class);
        $this->app->singleton(DigitalEntitlementGrantHookInterface::class, DigitalEntitlementGrantHook::class);
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
            $this->loadViewsFrom($viewsDir, 'digital-delivery');
        }

        $this->registerLivewireComponents();
        $this->registerNavigation();

        Event::listen(PaymentCaptured::class, GrantEntitlementsOnPaymentCaptured::class);
    }

    private function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('digital-delivery.control-center.digital-asset-manager', DigitalAssetManager::class);
        Livewire::component('digital-delivery.control-center.license-key-pool-manager', LicenseKeyPoolManager::class);
        Livewire::component('digital-delivery.storefront.digital-downloads-page', DigitalDownloadsPage::class);
    }

    private function registerNavigation(): void
    {
        $nav = $this->app->make(NavigationRegistryInterface::class);
        $nav->register(new NavigationItem('digital-delivery.assets', 'Digital Assets', 'control-center.digital-delivery.assets', 'Digital Delivery', 'digital-delivery.assets.view', 'tenant', 'folder', 10));
        $nav->register(new NavigationItem('digital-delivery.licenses', 'License Keys', 'control-center.digital-delivery.licenses', 'Digital Delivery', 'digital-delivery.licenses.view', 'tenant', 'key', 20));
    }
}
