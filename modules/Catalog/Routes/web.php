<?php

declare(strict_types=1);

use App\Core\Context\Middleware\ResolveContextMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Catalog\Livewire\AttributeManager;
use Modules\Catalog\Livewire\BrandManager;
use Modules\Catalog\Livewire\CategoryManager;
use Modules\Catalog\Livewire\ProductForm;
use Modules\Catalog\Livewire\ProductList;

Route::prefix('control-center/catalog')
    ->middleware(['web', ResolveContextMiddleware::class])
    ->group(function (): void {
        Route::get('products', ProductList::class)->name('control-center.catalog.products.index');
        Route::get('products/create', ProductForm::class)->name('control-center.catalog.products.create');
        Route::get('products/{id}/edit', ProductForm::class)->name('control-center.catalog.products.edit');
        Route::get('categories', CategoryManager::class)->name('control-center.catalog.categories');
        Route::get('attributes', AttributeManager::class)->name('control-center.catalog.attributes');
        Route::get('brands', BrandManager::class)->name('control-center.catalog.brands');
    });
