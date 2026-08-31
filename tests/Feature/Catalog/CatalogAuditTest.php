<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\ArchiveProductAction;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\Actions\UpdateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\Product;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'audit-catalog-tenant'],
        ['name' => 'Audit Catalog Tenant', 'status' => 'active']
    );
});

test('product creation, update and archiving generate audit records', function (): void {
    $createAction = app(CreateProductAction::class);
    $updateAction = app(UpdateProductAction::class);
    $archiveAction = app(ArchiveProductAction::class);

    // 1. Create
    $product = $createAction->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'AUDITED-SKU-100',
        translations: ['en' => ['name' => 'Audited Product']],
    ));

    $this->assertDatabaseHas('activity_log', [
        'event' => 'product.created',
        'subject_type' => Product::class,
        'subject_id' => $product->id,
    ]);

    // 2. Update
    $updateAction->execute($product, new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'AUDITED-SKU-100',
        translations: ['en' => ['name' => 'Audited Product Renamed']],
    ));

    $this->assertDatabaseHas('activity_log', [
        'event' => 'product.updated',
        'subject_type' => Product::class,
        'subject_id' => $product->id,
    ]);

    // 3. Archive
    $archiveAction->execute($product);

    $this->assertDatabaseHas('activity_log', [
        'event' => 'product.archived',
        'subject_type' => Product::class,
        'subject_id' => $product->id,
    ]);
});
