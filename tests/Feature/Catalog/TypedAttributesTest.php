<?php

declare(strict_types=1);

use App\Core\Tenancy\Models\Tenant;
use Modules\Catalog\Actions\AssignAttributeValuesAction;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\AttributeValueData;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\Attribute;
use Modules\Catalog\Models\AttributeOption;
use Modules\Catalog\Models\Product;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'attr-test-tenant'],
        ['name' => 'Attribute Test Tenant', 'status' => 'active']
    );

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'ATTR-PROD-001',
        translations: ['en' => ['name' => 'Attribute Test Product']],
    ));
});

test('can assign typed attribute values across multiple data types', function (): void {
    $textAttr = Attribute::create(['tenant_id' => $this->tenant->id, 'code' => 'material', 'type' => 'text']);
    $intAttr = Attribute::create(['tenant_id' => $this->tenant->id, 'code' => 'warranty_months', 'type' => 'integer']);
    $decimalAttr = Attribute::create(['tenant_id' => $this->tenant->id, 'code' => 'weight_kg', 'type' => 'decimal']);
    $boolAttr = Attribute::create(['tenant_id' => $this->tenant->id, 'code' => 'is_waterproof', 'type' => 'boolean']);

    $action = app(AssignAttributeValuesAction::class);

    $values = [
        new AttributeValueData(attributeId: $textAttr->id, textValue: '100% Organic Cotton'),
        new AttributeValueData(attributeId: $intAttr->id, intValue: 24),
        new AttributeValueData(attributeId: $decimalAttr->id, decimalValue: 1.75),
        new AttributeValueData(attributeId: $boolAttr->id, booleanValue: true),
    ];

    $updatedProduct = $action->execute($this->product, $values);

    $this->assertDatabaseHas('product_attribute_values', [
        'product_id' => $this->product->id,
        'attribute_id' => $textAttr->id,
        'text_value' => '100% Organic Cotton',
    ]);

    $this->assertDatabaseHas('product_attribute_values', [
        'product_id' => $this->product->id,
        'attribute_id' => $intAttr->id,
        'int_value' => 24,
    ]);

    $this->assertDatabaseHas('product_attribute_values', [
        'product_id' => $this->product->id,
        'attribute_id' => $decimalAttr->id,
        'decimal_value' => 1.7500,
    ]);

    $this->assertDatabaseHas('product_attribute_values', [
        'product_id' => $this->product->id,
        'attribute_id' => $boolAttr->id,
        'boolean_value' => true,
    ]);
});

test('relational multiselect stores options in pivot table for fast querying', function (): void {
    $multiAttr = Attribute::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'features',
        'type' => 'multiselect',
        'is_filterable' => true,
    ]);

    $opt1 = AttributeOption::create(['attribute_id' => $multiAttr->id, 'code' => 'bluetooth']);
    $opt2 = AttributeOption::create(['attribute_id' => $multiAttr->id, 'code' => 'wifi']);
    $opt3 = AttributeOption::create(['attribute_id' => $multiAttr->id, 'code' => 'nfc']);

    $action = app(AssignAttributeValuesAction::class);

    $action->execute($this->product, [
        new AttributeValueData(attributeId: $multiAttr->id, optionIds: [$opt1->id, $opt2->id]),
    ]);

    $this->assertDatabaseHas('product_attribute_options', [
        'product_id' => $this->product->id,
        'attribute_id' => $multiAttr->id,
        'attribute_option_id' => $opt1->id,
    ]);

    $this->assertDatabaseHas('product_attribute_options', [
        'product_id' => $this->product->id,
        'attribute_id' => $multiAttr->id,
        'attribute_option_id' => $opt2->id,
    ]);

    $this->assertDatabaseMissing('product_attribute_options', [
        'product_id' => $this->product->id,
        'attribute_id' => $multiAttr->id,
        'attribute_option_id' => $opt3->id,
    ]);

    // Relational query without scanning JSON:
    $matchingProducts = Product::whereHas('attributeOptions', function ($q) use ($multiAttr, $opt1): void {
        $q->where('attribute_id', $multiAttr->id)->where('attribute_option_id', $opt1->id);
    })->get();

    expect($matchingProducts)->toHaveCount(1)
        ->and($matchingProducts->first()->id)->toBe($this->product->id);
});
