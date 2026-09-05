<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Auctions\Livewire\ControlCenter\AuctionManager;

Route::prefix('control-center/auctions')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('control-center.auctions.')
    ->group(function (): void {
        Route::get('/auctions', AuctionManager::class)->name('auctions');
    });
