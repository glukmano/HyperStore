<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Catalog\Http\Controllers\Api\V1\AttributeApiController;
use Modules\Catalog\Http\Controllers\Api\V1\AttributeSetApiController;
use Modules\Catalog\Http\Controllers\Api\V1\BrandApiController;
use Modules\Catalog\Http\Controllers\Api\V1\CategoryApiController;
use Modules\Catalog\Http\Controllers\Api\V1\ProductApiController;
use Modules\Catalog\Http\Controllers\Api\V1\ProductTypeApiController;

Route::prefix('api/v1/catalog')
    ->middleware(['api', ResolveContextMiddleware::class])
    ->group(function (): void {
        Route::get('product-types', [ProductTypeApiController::class, 'index']);
        Route::get('product-types/{id}', [ProductTypeApiController::class, 'show']);

        Route::apiResource('products', ProductApiController::class);
        Route::post('products/{id}/publish', [ProductApiController::class, 'publish']);
        Route::post('products/{id}/attributes', [ProductApiController::class, 'assignAttributes']);
        Route::post('products/{id}/variants', [ProductApiController::class, 'createVariant']);

        Route::apiResource('categories', CategoryApiController::class);
        Route::apiResource('brands', BrandApiController::class);
        Route::apiResource('attributes', AttributeApiController::class);
        Route::apiResource('attribute-sets', AttributeSetApiController::class);
    });
