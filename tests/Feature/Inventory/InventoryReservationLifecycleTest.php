<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Event;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Events\InventoryCommitted;
use Modules\Inventory\Events\InventoryReservationReleased;
use Modules\Inventory\Events\InventoryReserved;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\ValueObjects\Quantity;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create(['slug' => 'res-lifecycle-tenant', 'name' => 'Reservation Lifecycle Tenant', 'status' => 'active']);

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

test('InventoryReservationService splits requested quantity and emits InventoryReserved', function (): void {
    Event::fake([InventoryReserved::class]);
    $service = app(InventoryReservationServiceInterface::class);
    $context = new InventoryContext(tenantId: $this->tenant->id);

    $result = $service->reserve($this->tenant->id, 'cart-res-777', $this->product->id, null, Quantity::fromString('7.0000'), $context);

    expect($result->isSuccess)->toBeTrue()
        ->and($result->reservation)->not->toBeNull()
        ->and($result->reservation->status)->toBe('active')
        ->and($result->allocations)->toHaveCount(2);

    $this->stock1->refresh();
    $this->stock2->refresh();

    expect($this->stock1->reserved)->toBe('4.0000')
        ->and($this->stock2->reserved)->toBe('3.0000');

    Event::assertDispatched(InventoryReserved::class);
});

test('InventoryReservationService enforces backorder_limit correctly', function (): void {
    $this->stock1->update([
        'on_hand' => '2.0000',
        'backorder_mode' => 'allow_with_limit',
        'backorder_limit' => '5.0000',
    ]);
    $this->stock2->update(['on_hand' => '0.0000', 'backorder_mode' => 'deny']);

    $service = app(InventoryReservationServiceInterface::class);
    $context = new InventoryContext(tenantId: $this->tenant->id);

    // Request 7 units (2 on-hand + 5 backorder limit = exact max allowed)
    $resOk = $service->reserve($this->tenant->id, 'res-bo-exact', $this->product->id, null, Quantity::fromString('7.0000'), $context);
    expect($resOk->isSuccess)->toBeTrue();

    // Next request exceeds backorder limit -> MUST FAIL
    $resFail = $service->reserve($this->tenant->id, 'res-bo-exceed', $this->product->id, null, Quantity::fromString('1.0000'), $context);
    expect($resFail->isSuccess)->toBeFalse()
        ->and($resFail->message)->toContain('backorder limit');
});

test('InventoryReservationService release returns reserved quantity and emits event', function (): void {
    Event::fake([InventoryReservationReleased::class]);
    $service = app(InventoryReservationServiceInterface::class);
    $context = new InventoryContext(tenantId: $this->tenant->id);

    $service->reserve($this->tenant->id, 'cart-res-release-test', $this->product->id, null, Quantity::fromString('3.0000'), $context);
    $released = $service->release($this->tenant->id, 'cart-res-release-test');
    expect($released)->toBeTrue();

    $this->stock1->refresh();
    expect($this->stock1->reserved)->toBe('0.0000');

    Event::assertDispatched(InventoryReservationReleased::class);
});

test('InventoryReservationService commit deducts on_hand and emits InventoryCommitted', function (): void {
    Event::fake([InventoryCommitted::class]);
    $service = app(InventoryReservationServiceInterface::class);
    $context = new InventoryContext(tenantId: $this->tenant->id);

    $service->reserve($this->tenant->id, 'cart-res-commit-test', $this->product->id, null, Quantity::fromString('2.0000'), $context);
    $committed = $service->commit($this->tenant->id, 'cart-res-commit-test');

    expect($committed)->toBeTrue();

    $this->stock1->refresh();
    expect($this->stock1->on_hand)->toBe('2.0000')
        ->and($this->stock1->reserved)->toBe('0.0000');

    Event::assertDispatched(InventoryCommitted::class);
});
