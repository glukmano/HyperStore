<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\POS\Livewire\ControlCenter\CashMovementLedgerViewer;
use Modules\POS\Livewire\ControlCenter\ManualDiscountAuditViewer;
use Modules\POS\Livewire\ControlCenter\RegisterManager;
use Modules\POS\Livewire\ControlCenter\RegisterSessionMonitor;
use Modules\POS\Livewire\Terminal\PosRegisterOpenClose;
use Modules\POS\Livewire\Terminal\PosTerminal;

Route::prefix('control-center/pos')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('control-center.pos.')
    ->group(function (): void {
        Route::get('/registers', RegisterManager::class)->name('registers');
        Route::get('/sessions', RegisterSessionMonitor::class)->name('sessions');
        Route::get('/cash-movements', CashMovementLedgerViewer::class)->name('cash-movements');
        Route::get('/manual-discounts', ManualDiscountAuditViewer::class)->name('manual-discounts');
    });

Route::prefix('pos')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('pos.')
    ->group(function (): void {
        Route::get('/register', PosRegisterOpenClose::class)->name('register');
        Route::get('/terminal/{session}', PosTerminal::class)->name('terminal.sale');
    });
