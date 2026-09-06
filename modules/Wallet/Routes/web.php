<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Wallet\Livewire\ControlCenter\StoreValueAccountManager;
use Modules\Wallet\Livewire\Storefront\WalletPage;

Route::prefix('control-center/wallet')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('control-center.wallet.')
    ->group(function (): void {
        Route::get('/accounts', StoreValueAccountManager::class)->name('accounts');
    });

Route::prefix('account/wallet')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('account.wallet.')
    ->group(function (): void {
        Route::get('/', WalletPage::class)->name('dashboard');
    });
