<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Order\Livewire\OrderDetail;
use Modules\Order\Livewire\OrderList;
use Modules\Order\Livewire\ReturnManager;

Route::prefix('control-center/orders')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('control-center.orders.')
    ->group(function (): void {
        Route::get('orders', OrderList::class)->name('orders.index');
        Route::get('orders/{orderId}', OrderDetail::class)->name('orders.show');
        Route::get('returns', ReturnManager::class)->name('returns.index');
    });
