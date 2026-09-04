<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Marketplace\Livewire\VendorDetail;
use Modules\Marketplace\Livewire\VendorList;

Route::prefix('control-center/vendors')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('control-center.vendors.')
    ->group(function (): void {
        Route::get('/', VendorList::class)->name('index');
        Route::get('/{vendorId}', VendorDetail::class)->name('show');
    });
