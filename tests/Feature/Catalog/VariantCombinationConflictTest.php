<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\Actions\CreateVariantAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\DTOs\VariantData;
use Modules\Catalog\Models\Attribute;
use Modules\Catalog\Models\AttributeOption;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'variant-conflict-tenant'],
        ['name' => 'Variant Conflict Tenant', 'status' => 'active']
    );

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'variable',
        sku: 'TSHIRT-BASE',
        translations: ['en' => ['name' => 'Base T-Shirt']],
    ));

    $this->colorAttr = Attribute::create(['tenant_id' => $this->tenant->id, 'code' => 'color', 'type' => 'select']);
    $this->sizeAttr = Attribute::create(['tenant_id' => $this->tenant->id, 'code' => 'size', 'type' => 'select']);

    $this->redOpt = AttributeOption::create(['attribute_id' => $this->colorAttr->id, 'code' => 'red']);
    $this->mOpt = AttributeOption::create(['attribute_id' => $this->sizeAttr->id, 'code' => 'm']);
});

test('Color=Red + Size=M conflicts with Size=M + Color=Red for the same Product', function (): void {
    $action = app(CreateVariantAction::class);

    // 1. Create Variant with [Color => Red, Size => M]
    $variant1 = $action->execute(new VariantData(
        productId: $this->product->id,
        sku: 'TSHIRT-RED-M',
        options: [
            $this->colorAttr->id => $this->redOpt->id,
            $this->sizeAttr->id => $this->mOpt->id,
        ],
    ));

    expect($variant1->id)->toBeGreaterThan(0);

    // 2. Attempt to create Variant with [Size => M, Color => Red] (different order)
    expect(fn () => $action->execute(new VariantData(
        productId: $this->product->id,
        sku: 'TSHIRT-M-RED',
        options: [
            $this->sizeAttr->id => $this->mOpt->id,
            $this->colorAttr->id => $this->redOpt->id,
        ],
    )))->toThrow(QueryException::class);
});
