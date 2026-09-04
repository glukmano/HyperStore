<?php

declare(strict_types=1);

namespace Modules\Dropshipping;

use App\Core\Modular\ModuleServiceProvider;
use Modules\Dropshipping\Adapters\SupplierExternalStockProvider;
use Modules\Dropshipping\Contracts\DropshipOrderOrchestratorInterface;
use Modules\Dropshipping\Contracts\SupplierInvoiceReconciliationServiceInterface;
use Modules\Dropshipping\Contracts\SupplierRoutingEngineInterface;
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
    }
}
