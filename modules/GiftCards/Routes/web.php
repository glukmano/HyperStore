<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\GiftCards\Livewire\ControlCenter\GiftCardManager;

Route::prefix('control-center/gift-cards')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('control-center.gift-cards.')
    ->group(function (): void {
        Route::get('/manage', GiftCardManager::class)->name('manage');
    });
