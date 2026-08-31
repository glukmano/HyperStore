<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'loc-fallback-tenant'],
        ['name' => 'Loc Fallback Tenant', 'status' => 'active']
    );
});

test('translations fall back to default English when requested locale translation is missing', function (): void {
    $product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'FALLBACK-SKU-001',
        translations: [
            'en' => ['name' => 'English Only Shirt'],
        ],
    ));

    // Requesting French (which is not stored) should fall back to English
    expect($product->translation('fr')?->name)->toBe('English Only Shirt')
        ->and($product->name)->toBe('English Only Shirt');
});

test('RTL language name is correctly retrieved', function (): void {
    $product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'AR-SKU-001',
        translations: [
            'ar' => ['name' => 'قميص قطني فاخر'],
            'en' => ['name' => 'Luxury Cotton Shirt'],
        ],
    ));

    expect($product->translation('ar')?->name)->toBe('قميص قطني فاخر');
});
