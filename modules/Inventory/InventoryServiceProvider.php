<?php

declare(strict_types=1);

namespace Modules\Inventory;

use App\Core\Modular\ModuleServiceProvider;
use Livewire\Livewire;
use Modules\Inventory\Commands\ExpireReservationsCommand;
use Modules\Inventory\Commands\ReconcileInventoryCommand;
use Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface;
use Modules\Inventory\Contracts\InventoryAvailabilityServiceInterface;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Contracts\InventorySourceQueryInterface;
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\Livewire\InventoryAdjustmentManager;
use Modules\Inventory\Livewire\InventoryMovementHistory;
use Modules\Inventory\Livewire\InventoryReceivingManager;
use Modules\Inventory\Livewire\InventoryReconciliationManager;
use Modules\Inventory\Livewire\InventorySourceManager;
use Modules\Inventory\Livewire\ReservationManager;
use Modules\Inventory\Livewire\StockItemManager;
use Modules\Inventory\Livewire\TransferManager;
use Modules\Inventory\Livewire\WarehouseManager;
use Modules\Inventory\Services\InventoryAdjustmentService;
use Modules\Inventory\Services\InventoryAvailabilityService;
use Modules\Inventory\Services\InventoryIdempotencyService;
use Modules\Inventory\Services\InventoryReconciliationService;
use Modules\Inventory\Services\InventoryReservationService;
use Modules\Inventory\Services\InventorySourceQueryService;
use Modules\Inventory\Services\InventoryTransferService;

class InventoryServiceProvider extends ModuleServiceProvider
{
    public function getPath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->singleton(InventoryIdempotencyService::class);
        $this->app->singleton(InventoryAvailabilityServiceInterface::class, InventoryAvailabilityService::class);
        $this->app->singleton(InventorySourceQueryInterface::class, InventorySourceQueryService::class);
        $this->app->singleton(InventoryReservationServiceInterface::class, InventoryReservationService::class);
        $this->app->singleton(InventoryAdjustmentServiceInterface::class, InventoryAdjustmentService::class);
        $this->app->singleton(InventoryTransferServiceInterface::class, InventoryTransferService::class);
        $this->app->singleton(InventoryReconciliationService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        $this->loadViewsFrom(__DIR__.'/Resources/views', 'inventory');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireReservationsCommand::class,
                ReconcileInventoryCommand::class,
            ]);
        }

        $this->registerLivewireComponents();
    }

    protected function registerLivewireComponents(): void
    {
        if (class_exists(Livewire::class)) {
            Livewire::component('inventory.warehouse-manager', WarehouseManager::class);
            Livewire::component('inventory.inventory-source-manager', InventorySourceManager::class);
            Livewire::component('inventory.stock-item-manager', StockItemManager::class);
            Livewire::component('inventory.movement-history', InventoryMovementHistory::class);
            Livewire::component('inventory.reservation-manager', ReservationManager::class);
            Livewire::component('inventory.transfer-manager', TransferManager::class);
            Livewire::component('inventory.adjustment-manager', InventoryAdjustmentManager::class);
            Livewire::component('inventory.receiving-manager', InventoryReceivingManager::class);
            Livewire::component('inventory.reconciliation-manager', InventoryReconciliationManager::class);
        }
    }
}
