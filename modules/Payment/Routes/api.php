<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Payment\Http\Controllers\AdminPaymentController;
use Modules\Payment\Http\Controllers\PaymentController;

Route::prefix('api/v1/orders/{orderIdentifier}/payments')->group(function (): void {
    Route::post('/', [PaymentController::class, 'initiate']);
    Route::get('/', [PaymentController::class, 'show']);
});

Route::prefix('api/v1/admin/payments')->middleware(['auth:sanctum'])->group(function (): void {
    Route::get('/{uuid}', [AdminPaymentController::class, 'show']);
    Route::post('/{uuid}/capture', [AdminPaymentController::class, 'capture']);
    Route::post('/{uuid}/refund', [AdminPaymentController::class, 'refund']);
    Route::post('/{uuid}/void', [AdminPaymentController::class, 'void']);
});
