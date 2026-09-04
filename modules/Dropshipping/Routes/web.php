<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Dropshipping\Livewire\PurchaseOrderDetail;
use Modules\Dropshipping\Livewire\PurchaseOrderList;
use Modules\Dropshipping\Livewire\SupplierDetail;
use Modules\Dropshipping\Livewire\SupplierList;

Route::prefix('control-center/dropshipping')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('control-center.dropshipping.')
    ->group(function (): void {
        Route::get('/suppliers', SupplierList::class)->name('suppliers.index');
        Route::get('/suppliers/{supplierId}', SupplierDetail::class)->name('suppliers.show');
        Route::get('/purchase-orders', PurchaseOrderList::class)->name('purchase-orders.index');
        Route::get('/purchase-orders/{purchaseOrderId}', PurchaseOrderDetail::class)->name('purchase-orders.show');
    });
