<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(InventoryPermissionSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'inv-api-tenant'],
        ['name' => 'Inventory API Tenant', 'status' => 'active']
    );

    $this->admin = User::firstOrCreate(
        ['email' => 'inv-api-admin@hyperstore.test'],
        ['name' => 'Inventory API Admin', 'password' => bcrypt('password'), 'is_super_admin' => true]
    );

    $this->actingAs($this->admin);
});

test('api can create warehouse and inventory source', function (): void {
    $response = $this->postJson('/api/v1/inventory/warehouses', [
        'code' => 'API-BERN-WH',
        'name' => 'Bern Distribution Hub',
        'country_code' => 'CH',
    ], ['X-Tenant-ID' => (string) $this->tenant->id]);

    $response->assertStatus(201)
        ->assertJsonPath('data.code', 'API-BERN-WH');

    $whId = $response->json('data.id');

    $srcResponse = $this->postJson('/api/v1/inventory/sources', [
        'code' => 'API-BERN-SRC',
        'name' => 'Bern Stock Source',
        'warehouse_id' => $whId,
    ], ['X-Tenant-ID' => (string) $this->tenant->id]);

    $srcResponse->assertStatus(201)
        ->assertJsonPath('data.code', 'API-BERN-SRC');
});

test('api checks availability and makes reservations via REST', function (): void {
    $product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'API-INV-PROD-1',
        translations: ['en' => ['name' => 'API Product']],
    ));

    $wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'API-WH-RES', 'name' => 'API Res Wh', 'country_code' => 'CH']);
    $src = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'API-SRC-RES', 'name' => 'API Res Src']);

    StockItem::create([
        'tenant_id' => $this->tenant->id,
        'inventory_source_id' => $src->id,
        'product_id' => $product->id,
        'on_hand' => '10.0000',
    ]);

    // Check Availability
    $availResponse = $this->postJson('/api/v1/inventory/availability', [
        'product_id' => $product->id,
    ], ['X-Tenant-ID' => (string) $this->tenant->id]);

    $availResponse->assertStatus(200)
        ->assertJsonPath('data.available_quantity', '10.0000')
        ->assertJsonPath('data.is_in_stock', true);

    // Reserve
    $resResponse = $this->postJson('/api/v1/inventory/reservations/reserve', [
        'reservation_key' => 'api-order-res-123',
        'product_id' => $product->id,
        'quantity' => '3.0000',
    ], ['X-Tenant-ID' => (string) $this->tenant->id]);

    $resResponse->assertStatus(201)
        ->assertJsonPath('data.reserved_quantity', '3.0000')
        ->assertJsonPath('data.status', 'active');
});
