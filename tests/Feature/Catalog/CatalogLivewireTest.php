<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Livewire\AttributeManager;
use Modules\Catalog\Livewire\BrandManager;
use Modules\Catalog\Livewire\CategoryManager;
use Modules\Catalog\Livewire\ProductForm;
use Modules\Catalog\Livewire\ProductList;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'livewire-test-tenant'],
        ['name' => 'Livewire Test Tenant', 'status' => 'active']
    );

    $this->admin = User::firstOrCreate(
        ['email' => 'lw-admin@hyperstore.test'],
        ['name' => 'Livewire Admin', 'password' => bcrypt('password'), 'is_super_admin' => true]
    );

    $this->actingAs($this->admin);
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
