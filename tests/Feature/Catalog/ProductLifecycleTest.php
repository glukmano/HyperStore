<?php

declare(strict_types=1);

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Modules\Catalog\Actions\ArchiveProductAction;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\Brand;
use Modules\Catalog\Models\Product;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'test-tenant-catalog'],
        ['name' => 'Catalog Test Tenant', 'status' => 'active']
    );

    $this->brand = Brand::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'nike',
        'status' => 'active',
    ]);
});

test('can create a canonical product with multi-language translations', function (): void {
    $action = app(CreateProductAction::class);

    $data = new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'NIKE-AIR-MAX-90',
        translations: [
            'en' => ['name' => 'Nike Air Max 90', 'short_description' => 'Classic running shoes'],
            'ar' => ['name' => 'نايكي اير ماكس 90', 'short_description' => 'حذاء رياضي كلاسيكي'],
        ],
        brandId: $this->brand->id,
        status: 'active',
    );

    $product = $action->execute($data);

    expect($product)->toBeInstanceOf(Product::class)
        ->and($product->sku)->toBe('NIKE-AIR-MAX-90')
        ->and($product->status)->toBe('active')
        ->and($product->translations)->toHaveCount(2)
        ->and($product->translation('en')->name)->toBe('Nike Air Max 90')
        ->and($product->translation('ar')->name)->toBe('نايكي اير ماكس 90');
});

test('sku is unique per tenant but duplicate sku is allowed across different tenants', function (): void {
    $action = app(CreateProductAction::class);

    $data1 = new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'SHARED-SKU-001',
        translations: ['en' => ['name' => 'Item Tenant 1']],
    );
    $action->execute($data1);

    // Duplicate SKU in same tenant should fail
    expect(fn () => $action->execute($data1))
        ->toThrow(QueryException::class);

    // Same SKU in another tenant should succeed
    $tenant2 = Tenant::firstOrCreate(
        ['slug' => 'other-tenant-catalog'],
        ['name' => 'Other Tenant', 'status' => 'active']
    );

    $data2 = new ProductData(
        tenantId: $tenant2->id,
        productType: 'physical',
        sku: 'SHARED-SKU-001',
        translations: ['en' => ['name' => 'Item Tenant 2']],
    );

    $product2 = $action->execute($data2);
    expect($product2->id)->toBeGreaterThan(0);
});

test('archiving product changes status to archived and preserves records', function (): void {
    $createAction = app(CreateProductAction::class);
    $archiveAction = app(ArchiveProductAction::class);

    $product = $createAction->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'RETIRE-ME-100',
        translations: ['en' => ['name' => 'Product to Retire']],
        status: 'active',
    ));

    $archived = $archiveAction->execute($product);

    expect($archived->status)->toBe('archived')
        ->and($archived->isArchived())->toBeTrue();

    // Verify it still exists in database
    $this->assertDatabaseHas('products', [
        'id' => $product->id,
        'status' => 'archived',
    ]);
});
