<?php

declare(strict_types=1);

namespace Modules\Dropshipping;

use App\Core\Modular\ModuleServiceProvider;
use Modules\Dropshipping\Contracts\DropshipOrderOrchestratorInterface;
use Modules\Dropshipping\Contracts\SupplierInvoiceReconciliationServiceInterface;
use Modules\Dropshipping\Contracts\SupplierRoutingEngineInterface;
use Modules\Dropshipping\Services\DropshipOrderOrchestrator;
use Modules\Dropshipping\Services\SupplierInvoiceReconciliationService;
use Modules\Dropshipping\Services\SupplierRoutingEngine;

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
    }

    public function boot(): void
    {
        parent::boot();
    }
}
