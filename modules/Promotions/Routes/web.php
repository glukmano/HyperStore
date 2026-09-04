<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Promotions\Livewire\CouponManager;
use Modules\Promotions\Livewire\PromotionManager;

Route::prefix('control-center/promotions')->middleware(['web', 'auth', ResolveContextMiddleware::class])->group(function () {
    Route::get('/', PromotionManager::class)->name('control-center.promotions.index');
    Route::get('coupons', CouponManager::class)->name('control-center.promotions.coupons');
});
