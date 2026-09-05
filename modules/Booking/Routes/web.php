<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Booking\Livewire\ControlCenter\BookingResourceManager;

Route::prefix('control-center/booking')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('control-center.booking.')
    ->group(function (): void {
        Route::get('/resources', BookingResourceManager::class)->name('resources');
    });
