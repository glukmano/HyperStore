<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Pricing\Livewire\ExchangeRateManager;
use Modules\Pricing\Livewire\PriceBookManager;
use Modules\Pricing\Livewire\ProductPricingManager;
use Modules\Pricing\Livewire\TaxManager;

Route::prefix('control-center/pricing')->middleware(['web', 'auth'])->group(function () {
    Route::get('price-books', PriceBookManager::class)->name('control-center.pricing.price-books');
    Route::get('products', ProductPricingManager::class)->name('control-center.pricing.products');
    Route::get('exchange-rates', ExchangeRateManager::class)->name('control-center.pricing.exchange-rates');
    Route::get('taxes', TaxManager::class)->name('control-center.pricing.taxes');
});
