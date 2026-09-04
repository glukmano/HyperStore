<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\CatalogPermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Livewire\AttributeManager;
use Modules\Catalog\Livewire\AttributeSetManager;
use Modules\Catalog\Livewire\BrandManager;
use Modules\Catalog\Livewire\CategoryManager;
use Modules\Catalog\Livewire\ProductForm;
use Modules\Catalog\Livewire\ProductList;
use Modules\Catalog\Models\Attribute;
use Modules\Catalog\Models\AttributeSet;
use Modules\Catalog\Models\AttributeTranslation;
use Modules\Catalog\Models\Category;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(CatalogPermissionSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'livewire-test-tenant'],
        ['name' => 'Livewire Test Tenant', 'status' => 'active']
    );

    $this->admin = User::firstOrCreate(
        ['email' => 'lw-admin@hyperstore.test'],
        ['name' => 'Livewire Admin', 'password' => bcrypt('password'), 'is_super_admin' => true]
    );

    $this->actingAs($this->admin);

    app(ContextManager::class)->setTenant(
        TenantContext::from($this->tenant->id, $this->tenant->name)
    );
});

test('product list component renders products', function (): void {
    app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'LW-PRODUCT-001',
        translations: ['en' => ['name' => 'Livewire Shirt']],
        status: 'active',
    ));

    Livewire::test(ProductList::class)
        ->assertStatus(200)
        ->assertSee('LW-PRODUCT-001')
        ->assertSee('Livewire Shirt');
});

test('product form component can create a product', function (): void {
    Livewire::test(ProductForm::class)
        ->set('sku', 'LW-NEW-PHONE')
        ->set('name', 'Livewire Ultra Phone')
        ->set('productType', 'physical')
        ->set('status', 'active')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('control-center.catalog.products.index'));

    $this->assertDatabaseHas('products', [
        'sku' => 'LW-NEW-PHONE',
        'status' => 'active',
    ]);
});

test('category manager component creates categories', function (): void {
    Livewire::test(CategoryManager::class)
        ->set('code', 'smartphones')
        ->set('name', 'Smartphones')
        ->set('slug', 'smartphones')
        ->call('createCategory')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('categories', ['code' => 'smartphones']);
});

test('brand manager component creates brands', function (): void {
    Livewire::test(BrandManager::class)
        ->set('code', 'sony')
        ->set('name', 'Sony Corporation')
        ->set('slug', 'sony')
        ->call('createBrand')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('brands', ['code' => 'sony']);
});

test('attribute manager component creates attributes', function (): void {
    Livewire::test(AttributeManager::class)
        ->set('code', 'storage_gb')
        ->set('name', 'Internal Storage')
        ->set('type', 'integer')
        ->call('createAttribute')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('attributes', ['code' => 'storage_gb', 'type' => 'integer']);
});

test('product list archives a product via the existing ArchiveProductAction', function (): void {
    $product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'LW-ARCHIVE-001',
        translations: ['en' => ['name' => 'Archive Me']],
        status: 'active',
    ));

    Livewire::test(ProductList::class)
        ->call('openArchiveConfirm', $product->id)
        ->call('archiveProduct')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'archived']);
});

test('unauthorized user cannot archive a product', function (): void {
    $product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'LW-ARCHIVE-002',
        translations: ['en' => ['name' => 'No Archive']],
        status: 'active',
    ));

    $unauthorized = User::create(['name' => 'No Perms', 'email' => 'noperm-catalog@hyperstore.test', 'password' => bcrypt('password')]);
    $this->actingAs($unauthorized);

    Livewire::test(ProductList::class)
        ->call('openArchiveConfirm', $product->id)
        ->call('archiveProduct')
        ->assertForbidden();

    $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'active']);
});

test('category manager component edits an existing category', function (): void {
    Livewire::test(CategoryManager::class)
        ->set('code', 'laptops')
        ->set('name', 'Laptops')
        ->set('slug', 'laptops')
        ->call('createCategory');

    $category = Category::where('code', 'laptops')->firstOrFail();

    Livewire::test(CategoryManager::class)
        ->call('editCategory', $category->id)
        ->set('editName', 'Laptops & Notebooks')
        ->call('updateCategory')
        ->assertHasNoErrors();

    expect($category->translation()->name)->toBe('Laptops & Notebooks');
});

test('attribute manager edits and archives an attribute', function (): void {
    $attribute = Attribute::create(['tenant_id' => $this->tenant->id, 'code' => 'color', 'type' => 'text', 'status' => 'active']);
    AttributeTranslation::create(['attribute_id' => $attribute->id, 'locale' => 'en', 'name' => 'Color']);

    Livewire::test(AttributeManager::class)
        ->call('editAttribute', $attribute->id)
        ->set('editName', 'Colour')
        ->call('updateAttribute')
        ->assertHasNoErrors();

    expect($attribute->translation()->name)->toBe('Colour');

    Livewire::test(AttributeManager::class)
        ->call('openArchiveConfirm', $attribute->id)
        ->call('archiveAttribute')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('attributes', ['id' => $attribute->id, 'status' => 'archived']);
});

test('attribute set manager edits and archives a set', function (): void {
    $set = AttributeSet::create(['tenant_id' => $this->tenant->id, 'name' => 'Electronics Set', 'code' => 'electronics', 'status' => 'active']);

    Livewire::test(AttributeSetManager::class)
        ->call('editSet', $set->id)
        ->set('editName', 'Electronics Set v2')
        ->call('updateSet')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('attribute_sets', ['id' => $set->id, 'name' => 'Electronics Set v2']);

    Livewire::test(AttributeSetManager::class)
        ->call('openArchiveConfirm', $set->id)
        ->call('archiveSet')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('attribute_sets', ['id' => $set->id, 'status' => 'archived']);
});

test('unauthorized user cannot edit an attribute set', function (): void {
    $set = AttributeSet::create(['tenant_id' => $this->tenant->id, 'name' => 'Locked Set', 'code' => 'locked', 'status' => 'active']);

    $unauthorized = User::create(['name' => 'No Perms 2', 'email' => 'noperm-attrset@hyperstore.test', 'password' => bcrypt('password')]);
    $this->actingAs($unauthorized);

    Livewire::test(AttributeSetManager::class)
        ->call('editSet', $set->id)
        ->assertForbidden();
});
