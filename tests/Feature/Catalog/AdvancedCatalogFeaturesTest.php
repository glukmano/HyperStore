<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\CreateCategoryAction;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\Contracts\CategoryHierarchyValidatorInterface;
use Modules\Catalog\DTOs\CategoryData;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\ProductBundleItem;
use Modules\Catalog\Models\ProductCustomField;
use Modules\Catalog\Models\ProductCustomFieldOption;
use Modules\Catalog\Models\ProductCustomFieldOptionTranslation;
use Modules\Catalog\Models\ProductCustomFieldTranslation;
use Modules\Catalog\Models\ProductRelationship;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'advanced-catalog-tenant'],
        ['name' => 'Advanced Catalog Tenant', 'status' => 'active']
    );
});

test('category hierarchy prevents deep cycles during updates', function (): void {
    $action = app(CreateCategoryAction::class);
    $validator = app(CategoryHierarchyValidatorInterface::class);

    $catA = $action->execute(new CategoryData(tenantId: $this->tenant->id, code: 'cat-a', translations: ['en' => ['name' => 'Cat A', 'slug' => 'cat-a']]));
    $catB = $action->execute(new CategoryData(tenantId: $this->tenant->id, code: 'cat-b', translations: ['en' => ['name' => 'Cat B', 'slug' => 'cat-b']], parentId: $catA->id));
    $catC = $action->execute(new CategoryData(tenantId: $this->tenant->id, code: 'cat-c', translations: ['en' => ['name' => 'Cat C', 'slug' => 'cat-c']], parentId: $catB->id));

    // Trying to make Cat A a child of Cat C would create loop A -> B -> C -> A
    expect(fn () => $validator->assertNoCycle($catA, $catC->id))
        ->toThrow(\InvalidArgumentException::class, 'Cyclic relationship detected in category hierarchy.');
});

test('custom fields support localized options for buyer input', function (): void {
    $product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'custom',
        sku: 'CUSTOM-ENGRAVED-RING',
        translations: ['en' => ['name' => 'Custom Engraved Ring']],
    ));

    /** @var ProductCustomField $customField */
    $customField = ProductCustomField::create([
        'product_id' => $product->id,
        'type' => 'select',
        'code' => 'font_style',
        'is_required' => true,
    ]);

    ProductCustomFieldTranslation::create([
        'product_custom_field_id' => $customField->id,
        'locale' => 'en',
        'label' => 'Font Style',
        'help_text' => 'Choose your desired engraving font',
    ]);

    $opt = ProductCustomFieldOption::create([
        'product_custom_field_id' => $customField->id,
        'code' => 'cursive',
    ]);

    ProductCustomFieldOptionTranslation::create([
        'product_custom_field_option_id' => $opt->id,
        'locale' => 'en',
        'label' => 'Elegant Cursive',
    ]);

    expect($customField->translations)->toHaveCount(1)
        ->and($customField->options)->toHaveCount(1)
        ->and($customField->options->first()->translations->first()->label)->toBe('Elegant Cursive');
});

test('product bundles and composite items maintain relational hierarchy', function (): void {
    $createAction = app(CreateProductAction::class);

    $bundle = $createAction->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'bundle',
        sku: 'GAMING-SETUP-BUNDLE',
        translations: ['en' => ['name' => 'Ultimate Gaming Bundle']],
    ));

    $item1 = $createAction->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'GAMING-KEYBOARD',
        translations: ['en' => ['name' => 'Mechanical Keyboard']],
    ));

    $item2 = $createAction->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'GAMING-MOUSE',
        translations: ['en' => ['name' => 'Wireless Gaming Mouse']],
    ));

    ProductBundleItem::create([
        'parent_product_id' => $bundle->id,
        'item_product_id' => $item1->id,
        'quantity' => 1,
        'is_optional' => false,
    ]);

    ProductBundleItem::create([
        'parent_product_id' => $bundle->id,
        'item_product_id' => $item2->id,
        'quantity' => 1,
        'is_optional' => true,
    ]);

    expect($bundle->bundleItems)->toHaveCount(2)
        ->and($bundle->bundleItems->first()->itemProduct->sku)->toBe('GAMING-KEYBOARD')
        ->and($bundle->bundleItems->last()->is_optional)->toBeTrue();
});

test('product relationships support up_sell, cross_sell, and accessory links', function (): void {
    $createAction = app(CreateProductAction::class);

    $phone = $createAction->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'FLAGSHIP-PHONE',
        translations: ['en' => ['name' => 'Flagship Smartphone']],
    ));

    $case = $createAction->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'PHONE-SILICONE-CASE',
        translations: ['en' => ['name' => 'Silicone Case']],
    ));

    $charger = $createAction->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'FAST-CHARGER-65W',
        translations: ['en' => ['name' => '65W Fast Charger']],
    ));

    ProductRelationship::create([
        'product_id' => $phone->id,
        'related_product_id' => $case->id,
        'type' => 'accessory',
        'sort_order' => 1,
    ]);

    ProductRelationship::create([
        'product_id' => $phone->id,
        'related_product_id' => $charger->id,
        'type' => 'cross_sell',
        'sort_order' => 2,
    ]);

    expect($phone->relationships)->toHaveCount(2)
        ->and($phone->relationships->where('type', 'accessory')->first()->relatedProduct->sku)->toBe('PHONE-SILICONE-CASE');
});
