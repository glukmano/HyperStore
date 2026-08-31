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

    $this->tenantA = Tenant::create(['slug' => 'api-tenant-a', 'name' => 'Tenant A', 'status' => 'active']);
    $this->tenantB = Tenant::create(['slug' => 'api-tenant-b', 'name' => 'Tenant B', 'status' => 'active']);

    $this->admin = User::create([
        'email' => 'api-admin@hyperstore.test',
        'name' => 'API Admin',
        'password' => bcrypt('password'),
        'is_super_admin' => true,
    ]);

    $this->limitedUser = User::create([
        'email' => 'limited-user@hyperstore.test',
        'name' => 'Limited User',
        'password' => bcrypt('password'),
        'is_super_admin' => false,
    ]);

    $this->actingAs($this->admin, 'sanctum');
});

test('api returns 401 when tenant header is missing', function (): void {
    $response = $this->getJson('/api/v1/inventory/warehouses');
    $response->assertStatus(401);
});

test('api denies non-privileged user without required RBAC permission', function (): void {
    $this->actingAs($this->limitedUser, 'sanctum');

    $response = $this->postJson('/api/v1/inventory/warehouses', [
        'code' => 'BERN-FAIL-WH',
        'name' => 'Bern WH',
        'country_code' => 'CH',
    ], ['X-Tenant-ID' => (string) $this->tenantA->id]);

    $response->assertStatus(403);
});

test('api prevents cross-tenant stock mutation IDOR', function (): void {
    $productB = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenantB->id,
        productType: 'physical',
        sku: 'PROD-B-IDOR',
        translations: ['en' => ['name' => 'Product B']],
    ));

    $whB = Warehouse::create(['tenant_id' => $this->tenantB->id, 'code' => 'WH-B-IDOR', 'name' => 'Wh B', 'country_code' => 'CH']);
    $srcB = InventorySource::create(['tenant_id' => $this->tenantB->id, 'warehouse_id' => $whB->id, 'code' => 'SRC-B-IDOR', 'name' => 'Src B']);

    $stockB = StockItem::create([
        'tenant_id' => $this->tenantB->id,
        'inventory_source_id' => $srcB->id,
        'product_id' => $productB->id,
        'on_hand' => '10.0000',
    ]);

    // Request from Tenant A context trying to adjust Tenant B stock item -> MUST RETURN 404
    $response = $this->postJson('/api/v1/inventory/adjustments', [
        'stock_item_id' => $stockB->id,
        'delta' => '-5.0000',
        'movement_type' => 'damaged',
    ], ['X-Tenant-ID' => (string) $this->tenantA->id]);

    $response->assertStatus(404);
});
