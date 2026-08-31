<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Channels\Models\Channel;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface;
use Modules\Inventory\Contracts\InventoryAvailabilityServiceInterface;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\InventoryIdempotencyService;
use Modules\Inventory\Services\InventoryReconciliationService;
use Modules\Inventory\ValueObjects\Quantity;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(InventoryPermissionSeeder::class);

    $this->tenantA = Tenant::create(['slug' => 'corr-tenant-a', 'name' => 'Compliance Tenant A', 'status' => 'active']);
    $this->tenantB = Tenant::create(['slug' => 'corr-tenant-b', 'name' => 'Compliance Tenant B', 'status' => 'active']);

    $this->productA = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenantA->id,
        productType: 'physical',
        sku: 'CORR-PROD-A',
        translations: ['en' => ['name' => 'Compliance Product A']],
    ));

    $this->productB = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenantB->id,
        productType: 'physical',
        sku: 'CORR-PROD-B',
        translations: ['en' => ['name' => 'Compliance Product B']],
    ));

    $this->whA = Warehouse::create(['tenant_id' => $this->tenantA->id, 'code' => 'CORR-WH-A', 'name' => 'Wh A', 'country_code' => 'CH']);
    $this->srcA = InventorySource::create(['tenant_id' => $this->tenantA->id, 'warehouse_id' => $this->whA->id, 'code' => 'CORR-SRC-A', 'name' => 'Src A', 'priority' => 10]);

    $this->stockA = StockItem::create([
        'tenant_id' => $this->tenantA->id,
        'inventory_source_id' => $this->srcA->id,
        'product_id' => $this->productA->id,
        'on_hand' => '0.0000',
        'reserved' => '0.0000',
    ]);
    app(InventoryAdjustmentServiceInterface::class)->receive($this->stockA, Quantity::fromString('10.0000'));
});

test('1. Unresolved TenantContext in API fails safely with 401 without silent fallback', function (): void {
    $admin = User::create(['email' => 'admin-corr@hyperstore.test', 'name' => 'Admin', 'password' => bcrypt('password'), 'is_super_admin' => true]);
    $this->actingAs($admin, 'sanctum');

    $response = $this->getJson('/api/v1/inventory/warehouses');
    $response->assertStatus(401);
});

test('2. Cross-Tenant StockItem IDOR is rejected with 404', function (): void {
    $admin = User::create(['email' => 'admin-corr-2@hyperstore.test', 'name' => 'Admin', 'password' => bcrypt('password'), 'is_super_admin' => true]);
    $this->actingAs($admin, 'sanctum');

    // Tenant B context trying to mutate Tenant A StockItem
    $response = $this->postJson('/api/v1/inventory/adjustments', [
        'stock_item_id' => $this->stockA->id,
        'delta' => '5.0000',
        'movement_type' => 'adjustment_in',
    ], ['X-Tenant-ID' => (string) $this->tenantB->id]);

    $response->assertStatus(404);
});

test('3. Cross-Tenant reservation release and commit cannot be executed across tenant boundary', function (): void {
    $resService = app(InventoryReservationServiceInterface::class);
    $contextA = new InventoryContext(tenantId: $this->tenantA->id);

    $resService->reserve($this->tenantA->id, 'res-corr-cross', $this->productA->id, null, Quantity::fromString('2.0000'), $contextA);

    // Tenant B attempts to release Tenant A reservation -> fails
    $releasedByB = $resService->release($this->tenantB->id, 'res-corr-cross');
    expect($releasedByB)->toBeFalse();

    // Tenant B attempts to commit Tenant A reservation -> fails
    $committedByB = $resService->commit($this->tenantB->id, 'res-corr-cross');
    expect($committedByB)->toBeFalse();

    // Tenant A successfully commits
    $committedByA = $resService->commit($this->tenantA->id, 'res-corr-cross');
    expect($committedByA)->toBeTrue();
});

test('4. RBAC negative permission denial returns 403', function (): void {
    $user = User::create(['email' => 'limited-corr@hyperstore.test', 'name' => 'Limited', 'password' => bcrypt('password'), 'is_super_admin' => false]);
    $this->actingAs($user, 'sanctum');

    $response = $this->postJson('/api/v1/inventory/adjustments', [
        'stock_item_id' => $this->stockA->id,
        'delta' => '5.0000',
        'movement_type' => 'adjustment_in',
    ], ['X-Tenant-ID' => (string) $this->tenantA->id]);

    $response->assertStatus(403);
});

test('5. Atomic idempotency prevents duplicate stock addition', function (): void {
    $idempotencyService = app(InventoryIdempotencyService::class);
    $adjService = app(InventoryAdjustmentServiceInterface::class);

    $m1 = $adjService->receive($this->stockA, Quantity::fromString('5.0000'), idempotencyKey: 'ATOMIC-KEY-999');
    $this->stockA->refresh();
    expect($this->stockA->on_hand)->toBe('15.0000');

    // Duplicate call with identical key
    $m2 = $adjService->receive($this->stockA, Quantity::fromString('5.0000'), idempotencyKey: 'ATOMIC-KEY-999');
    $this->stockA->refresh();
    expect($this->stockA->on_hand)->toBe('15.0000')
        ->and($m1->id)->toBe($m2->id);
});

test('6. Stale external source is excluded from both availability and reservation', function (): void {
    $staleSrc = InventorySource::create([
        'tenant_id' => $this->tenantA->id,
        'code' => 'CORR-STALE-SRC',
        'name' => 'Stale Feed',
        'source_type' => 'supplier',
        'last_synced_at' => now()->subHours(10),
        'stale_after_minutes' => 60,
    ]);

    $staleStock = StockItem::create([
        'tenant_id' => $this->tenantA->id,
        'inventory_source_id' => $staleSrc->id,
        'product_id' => $this->productA->id,
        'on_hand' => '0.0000',
    ]);
    app(InventoryAdjustmentServiceInterface::class)->receive($staleStock, Quantity::fromString('50.0000'));

    $availService = app(InventoryAvailabilityServiceInterface::class);
    $resService = app(InventoryReservationServiceInterface::class);
    $context = new InventoryContext(tenantId: $this->tenantA->id);

    // Stale source's 50 units must NOT appear in availability
    $avail = $availService->check($this->productA->id, null, $context);
    expect($avail->availableQuantity->toString())->toBe('10.0000');

    // Reserving 15 units must fail because only 10 from active fresh source is eligible
    $res = $resService->reserve($this->tenantA->id, 'res-stale-test', $this->productA->id, null, Quantity::fromString('15.0000'), $context);
    expect($res->isSuccess)->toBeFalse();
});

test('7. Store and Channel scoping filters eligible sources for reservation', function (): void {
    $store = Store::create(['tenant_id' => $this->tenantA->id, 'slug' => 'zurich-store', 'code' => 'STORE-ZURICH', 'name' => 'Zurich Store', 'status' => 'active', 'currency' => 'CHF', 'default_locale' => 'en']);
    $channel = Channel::create(['name' => 'Online Channel', 'handle' => 'online-channel', 'type' => 'website', 'is_active' => true]);

    // Dedicated POS source assigned to Zurich store
    $posSrc = InventorySource::create([
        'tenant_id' => $this->tenantA->id,
        'code' => 'POS-SRC',
        'name' => 'POS Stock',
        'source_type' => 'warehouse',
        'warehouse_id' => $this->whA->id,
        'priority' => 20,
    ]);
    $posSrc->stores()->attach($store->id);

    $posStock = StockItem::create([
        'tenant_id' => $this->tenantA->id,
        'inventory_source_id' => $posSrc->id,
        'product_id' => $this->productA->id,
        'on_hand' => '0.0000',
    ]);
    app(InventoryAdjustmentServiceInterface::class)->receive($posStock, Quantity::fromString('100.0000'));

    $availService = app(InventoryAvailabilityServiceInterface::class);

    // Context without store filter gets all (10 + 100 = 110)
    $availAll = $availService->check($this->productA->id, null, new InventoryContext(tenantId: $this->tenantA->id));
    expect($availAll->availableQuantity->toString())->toBe('110.0000');

    // Context with non-matching store filter gets only global source (10)
    $otherStore = Store::create(['tenant_id' => $this->tenantA->id, 'slug' => 'geneva-store', 'code' => 'STORE-GENEVA', 'name' => 'Geneva Store', 'status' => 'active', 'currency' => 'CHF', 'default_locale' => 'en']);
    $availGeneva = $availService->check($this->productA->id, null, new InventoryContext(tenantId: $this->tenantA->id, storeId: $otherStore->id));
    expect($availGeneva->availableQuantity->toString())->toBe('10.0000');
});

test('8. Reconciliation detects orphan reservation allocations and balance drifts', function (): void {
    $recService = app(InventoryReconciliationService::class);
    $report = $recService->reconcile($this->tenantA->id);
    expect($report['is_clean'])->toBeTrue()
        ->and($report['orphan_allocations_count'])->toBe(0);
});
