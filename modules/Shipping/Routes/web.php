<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Shipping\Livewire\CarrierManager;
use Modules\Shipping\Livewire\PackageTypeManager;
use Modules\Shipping\Livewire\PickupLocationManager;
use Modules\Shipping\Livewire\RatePreviewTool;
use Modules\Shipping\Livewire\RateRuleManager;
use Modules\Shipping\Livewire\ShippingClassManager;
use Modules\Shipping\Livewire\ShippingMethodManager;
use Modules\Shipping\Livewire\ShippingRestrictionManager;
use Modules\Shipping\Livewire\ShippingZoneManager;

Route::middleware(['web', 'auth', ResolveContextMiddleware::class])->prefix('control-center/shipping')->name('control-center.shipping.')->group(function () {
    Route::get('zones', ShippingZoneManager::class)->name('zones');
    Route::get('methods', ShippingMethodManager::class)->name('methods');
    Route::get('rate-rules', RateRuleManager::class)->name('rate-rules');
    Route::get('classes', ShippingClassManager::class)->name('classes');
    Route::get('package-types', PackageTypeManager::class)->name('package-types');
    Route::get('carriers', CarrierManager::class)->name('carriers');
    Route::get('pickup-locations', PickupLocationManager::class)->name('pickup-locations');
    Route::get('restrictions', ShippingRestrictionManager::class)->name('restrictions');
    Route::get('rate-preview', RatePreviewTool::class)->name('rate-preview');
});
