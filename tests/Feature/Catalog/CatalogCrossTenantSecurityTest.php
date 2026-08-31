<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use InvalidArgumentException;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\Actions\PublishProductToStoreAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\DTOs\StorePublicationData;
use Modules\Catalog\Models\Brand;
use Modules\Catalog\Models\Category;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenantA = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a-sec', 'status' => 'active']);
    $this->tenantB = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b-sec', 'status' => 'active']);

    $this->productA = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenantA->id,
        productType: 'physical',
        sku: 'TENANT-A-PROD',
        translations: ['en' => ['name' => 'Tenant A Product']],
    ));

    $this->storeB = Store::create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Store B',
        'slug' => 'store-b',
        'status' => 'active',
    ]);
});

test('cannot publish product to a store belonging to another tenant', function (): void {
    $action = app(PublishProductToStoreAction::class);

    expect(fn () => $action->execute(new StorePublicationData(
        productId: $this->productA->id,
        storeId: $this->storeB->id,
        status: 'published',
        translations: ['en' => ['slug' => 'invalid-cross-slug']],
    )))->toThrow(InvalidArgumentException::class, 'Cross-tenant violation');
});

test('cannot associate product with brand belonging to another tenant', function (): void {
    $brandB = Brand::create([
        'tenant_id' => $this->tenantB->id,
        'code' => 'brand-b',
        'status' => 'active',
    ]);

    $action = app(CreateProductAction::class);

    expect(fn () => $action->execute(new ProductData(
        tenantId: $this->tenantA->id,
        productType: 'physical',
        sku: 'INVALID-BRAND-PROD',
        translations: ['en' => ['name' => 'Invalid Brand Prod']],
        brandId: $brandB->id,
    )))->toThrow(InvalidArgumentException::class, 'Cross-tenant violation');
});

test('cannot associate product with category belonging to another tenant', function (): void {
    $catB = Category::create([
        'tenant_id' => $this->tenantB->id,
        'code' => 'cat-b',
        'status' => 'active',
    ]);

    $action = app(CreateProductAction::class);

    expect(fn () => $action->execute(new ProductData(
        tenantId: $this->tenantA->id,
        productType: 'physical',
        sku: 'INVALID-CAT-PROD',
        translations: ['en' => ['name' => 'Invalid Cat Prod']],
        categoryIds: [$catB->id],
    )))->toThrow(InvalidArgumentException::class, 'Cross-tenant violation');
});
