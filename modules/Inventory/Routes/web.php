<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Livewire\InventoryAdjustmentManager;
use Modules\Inventory\Livewire\InventoryMovementHistory;
use Modules\Inventory\Livewire\InventoryReceivingManager;
use Modules\Inventory\Livewire\InventoryReconciliationManager;
use Modules\Inventory\Livewire\InventorySourceManager;
use Modules\Inventory\Livewire\ReservationManager;
use Modules\Inventory\Livewire\StockItemManager;
use Modules\Inventory\Livewire\TransferManager;
use Modules\Inventory\Livewire\WarehouseManager;

Route::middleware(['web', 'auth'])->prefix('control-center/inventory')->name('control-center.inventory.')->group(function () {
    Route::get('warehouses', WarehouseManager::class)->name('warehouses');
    Route::get('sources', InventorySourceManager::class)->name('sources');
    Route::get('stock', StockItemManager::class)->name('stock');
    Route::get('movements', InventoryMovementHistory::class)->name('movements');
    Route::get('reservations', ReservationManager::class)->name('reservations');
    Route::get('transfers', TransferManager::class)->name('transfers');
    Route::get('adjustments', InventoryAdjustmentManager::class)->name('adjustments');
    Route::get('receive', InventoryReceivingManager::class)->name('receive');
    Route::get('reconcile', InventoryReconciliationManager::class)->name('reconcile');
});
