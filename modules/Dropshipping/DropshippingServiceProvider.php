<?php

declare(strict_types=1);

namespace Modules\Dropshipping;

use App\Core\Modular\ModuleServiceProvider;
use App\Core\Navigation\Contracts\NavigationRegistryInterface;
use App\Core\Navigation\DTOs\NavigationItem;
use Livewire\Livewire;
use Modules\Dropshipping\Adapters\SupplierExternalStockProvider;
use Modules\Dropshipping\Contracts\DropshipOrderOrchestratorInterface;
use Modules\Dropshipping\Contracts\SupplierInvoiceReconciliationServiceInterface;
use Modules\Dropshipping\Contracts\SupplierRoutingEngineInterface;
use Modules\Dropshipping\Livewire\PurchaseOrderDetail;
use Modules\Dropshipping\Livewire\PurchaseOrderList;
use Modules\Dropshipping\Livewire\SupplierDetail;
use Modules\Dropshipping\Livewire\SupplierList;
use Modules\Dropshipping\Services\DropshipOrderOrchestrator;
use Modules\Dropshipping\Services\SupplierInvoiceReconciliationService;
use Modules\Dropshipping\Services\SupplierRoutingEngine;
use Modules\Inventory\Contracts\ExternalStockProviderInterface;

class DropshippingServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(SupplierRoutingEngineInterface::class, SupplierRoutingEngine::class);
        $this->app->singleton(SupplierRoutingEngine::class);

        $this->app->singleton(DropshipOrderOrchestratorInterface::class, DropshipOrderOrchestrator::class);
        $this->app->singleton(DropshipOrderOrchestrator::class);

        $this->app->singleton(SupplierInvoiceReconciliationServiceInterface::class, SupplierInvoiceReconciliationService::class);
        $this->app->singleton(SupplierInvoiceReconciliationService::class);

        $this->app->singleton(ExternalStockProviderInterface::class, SupplierExternalStockProvider::class);
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
            $this->loadViewsFrom($viewsDir, 'dropshipping');
        }

        $this->registerLivewireComponents();
        $this->registerNavigation();
    }

    private function registerLivewireComponents(): void
    {
        if (class_exists(Livewire::class)) {
            Livewire::component('dropshipping.supplier-list', SupplierList::class);
            Livewire::component('dropshipping.supplier-detail', SupplierDetail::class);
            Livewire::component('dropshipping.purchase-order-list', PurchaseOrderList::class);
            Livewire::component('dropshipping.purchase-order-detail', PurchaseOrderDetail::class);
        }
    }

    private function registerNavigation(): void
    {
        $nav = $this->app->make(NavigationRegistryInterface::class);
        $nav->register(new NavigationItem('dropshipping.suppliers', 'Suppliers', 'control-center.dropshipping.suppliers.index', 'Dropshipping', 'suppliers.view', 'tenant', '🚚', 10));
        $nav->register(new NavigationItem('dropshipping.purchase-orders', 'Purchase Orders', 'control-center.dropshipping.purchase-orders.index', 'Dropshipping', 'purchase_orders.view', 'tenant', '📦', 20));
    }
}
