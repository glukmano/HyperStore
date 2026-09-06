<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Subscriptions\Livewire\ControlCenter\SubscriptionManager;
use Modules\Subscriptions\Livewire\ControlCenter\SubscriptionPlanManager;
use Modules\Subscriptions\Livewire\Storefront\SubscriptionsPage;

Route::prefix('control-center/subscriptions')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('control-center.subscriptions.')
    ->group(function (): void {
        Route::get('/plans', SubscriptionPlanManager::class)->name('plans');
        Route::get('/manage', SubscriptionManager::class)->name('manage');
    });

Route::prefix('account/subscriptions')
    ->middleware(['web', 'auth', ResolveContextMiddleware::class])
    ->name('account.subscriptions.')
    ->group(function (): void {
        Route::get('/', SubscriptionsPage::class)->name('index');
    });
