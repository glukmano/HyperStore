<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Payment\Livewire\PaymentDetail;
use Modules\Payment\Livewire\PaymentList;

Route::prefix('control-center/payments')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('control-center.payments.')
    ->group(function (): void {
        Route::get('/', PaymentList::class)->name('index');
        Route::get('/{uuid}', PaymentDetail::class)->name('show');
    });
