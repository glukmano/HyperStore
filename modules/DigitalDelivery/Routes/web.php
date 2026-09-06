<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\DigitalDelivery\Http\Controllers\DigitalDownloadController;
use Modules\DigitalDelivery\Livewire\ControlCenter\DigitalAssetManager;
use Modules\DigitalDelivery\Livewire\ControlCenter\LicenseKeyPoolManager;
use Modules\DigitalDelivery\Livewire\Storefront\DigitalDownloadsPage;

Route::prefix('control-center/digital-delivery')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('control-center.digital-delivery.')
    ->group(function (): void {
        Route::get('/assets', DigitalAssetManager::class)->name('assets');
        Route::get('/licenses', LicenseKeyPoolManager::class)->name('licenses');
    });

Route::prefix('account/downloads')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('account.downloads.')
    ->group(function (): void {
        Route::get('/', DigitalDownloadsPage::class)->name('index');
    });

Route::get('/downloads/{entitlementUuid}', [DigitalDownloadController::class, 'download'])
    ->middleware(['web', 'auth', 'signed', ResolveContextMiddleware::class])
    ->name('storefront.digital.download');
