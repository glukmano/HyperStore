<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Fulfillment\Livewire\FulfillmentPreviewTool;
use Modules\Fulfillment\Livewire\FulfillmentSourceManager;
use Modules\Fulfillment\Livewire\FulfillmentStrategyManager;

Route::middleware(['web', 'auth', ResolveContextMiddleware::class])->prefix('control-center/fulfillment')->name('control-center.fulfillment.')->group(function () {
    Route::get('sources', FulfillmentSourceManager::class)->name('sources');
    Route::get('strategies', FulfillmentStrategyManager::class)->name('strategies');
    Route::get('preview', FulfillmentPreviewTool::class)->name('preview');
});
