<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\ValueObjects\Quantity;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'res-lifecycle-tenant'],
        ['name' => 'Reservation Lifecycle Tenant', 'status' => 'active']
    );

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'RES-LIFECYCLE-SKU',
        translations: ['en' => ['name' => 'Reservation Product']],
    ));

    $this->wh1 = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH-RES-1', 'name' => 'Wh 1', 'country_code' => 'CH']);
    $this->wh2 = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH-RES-2', 'name' => 'Wh 2', 'country_code' => 'CH']);

    $this->src1 = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->wh1->id, 'code' => 'SRC-RES-1', 'name' => 'Source 1', 'priority' => 10]);
    $this->src2 = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->wh2->id, 'code' => 'SRC-RES-2', 'name' => 'Source 2', 'priority' => 5]);

    // Source 1 has 4 units, Source 2 has 6 units
    $this->stock1 = StockItem::create([
        'tenant_id' => $this->tenant->id,
        'inventory_source_id' => $this->src1->id,
        'product_id' => $this->product->id,
        'on_hand' => '4.0000',
        'reserved' => '0.0000',
    ]);

    $this->stock2 = StockItem::create([
        'tenant_id' => $this->tenant->id,
        'inventory_source_id' => $this->src2->id,
        'product_id' => $this->product->id,
        'on_hand' => '6.0000',
        'reserved' => '0.0000',
    ]);
});

test('InventoryReservationService splits requested quantity across multiple eligible sources', function (): void {
    $service = app(InventoryReservationServiceInterface::class);
    $context = new InventoryContext(tenantId: $this->tenant->id);

    // Request 7 units (takes 4 from Source 1 and 3 from Source 2)
    $result = $service->reserve('cart-res-777', $this->product->id, null, Quantity::fromString('7.0000'), $context);

    expect($result->isSuccess)->toBeTrue()
        ->and($result->reservation)->not->toBeNull()
        ->and($result->reservation->status)->toBe('active')
        ->and($result->allocations)->toHaveCount(2);

    $this->stock1->refresh();
    $this->stock2->refresh();

    expect($this->stock1->reserved)->toBe('4.0000')
        ->and($this->stock2->reserved)->toBe('3.0000');
});

test('InventoryReservationService release returns reserved quantity to available stock', function (): void {
    $service = app(InventoryReservationServiceInterface::class);
    $context = new InventoryContext(tenantId: $this->tenant->id);

    $service->reserve('cart-res-release-test', $this->product->id, null, Quantity::fromString('3.0000'), $context);

    $this->stock1->refresh();
    expect($this->stock1->reserved)->toBe('3.0000');

    $released = $service->release('cart-res-release-test');
    expect($released)->toBeTrue();

    $this->stock1->refresh();
    expect($this->stock1->reserved)->toBe('0.0000');

    $res = InventoryReservation::where('reservation_key', 'cart-res-release-test')->first();
    expect($res->status)->toBe('released');
});

test('InventoryReservationService commit deducts on_hand and logs reservation_commit movement', function (): void {
    $service = app(InventoryReservationServiceInterface::class);
    $context = new InventoryContext(tenantId: $this->tenant->id);

    $service->reserve('cart-res-commit-test', $this->product->id, null, Quantity::fromString('2.0000'), $context);
    $committed = $service->commit('cart-res-commit-test');

    expect($committed)->toBeTrue();

    $this->stock1->refresh();
    // on_hand was 4, now 2. reserved was 2, now 0.
    expect($this->stock1->on_hand)->toBe('2.0000')
        ->and($this->stock1->reserved)->toBe('0.0000');

    $this->assertDatabaseHas('inventory_movements', [
        'stock_item_id' => $this->stock1->id,
        'movement_type' => 'reservation_commit',
        'reference_id' => 'cart-res-commit-test',
    ]);
});
