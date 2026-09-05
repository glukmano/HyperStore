<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\B2B\Livewire\ControlCenter\CompanyCreditManager;
use Modules\B2B\Livewire\ControlCenter\CompanyManager;
use Modules\B2B\Livewire\ControlCenter\QuoteManager;
use Modules\B2B\Livewire\Storefront\CompanyAccountPage;
use Modules\B2B\Livewire\Storefront\QuoteRequestPage;

Route::prefix('control-center/b2b')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('control-center.b2b.')
    ->group(function (): void {
        Route::get('/companies', CompanyManager::class)->name('companies');
        Route::get('/quotes', QuoteManager::class)->name('quotes');
        Route::get('/credit', CompanyCreditManager::class)->name('credit');
    });

Route::prefix('account/company')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('account.company.')
    ->group(function (): void {
        Route::get('/', CompanyAccountPage::class)->name('dashboard');
        Route::get('/quotes', QuoteRequestPage::class)->name('quotes');
    });
