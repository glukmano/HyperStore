<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use LogicException;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface;
use Modules\Inventory\Events\InventoryAdjusted;
use Modules\Inventory\Events\InventoryReceived;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\ValueObjects\Quantity;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create(['slug' => 'stock-move-tenant', 'name' => 'Stock Movement Tenant', 'status' => 'active']);

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'TEST-STOCK-ITEM-SKU',
        translations: ['en' => ['name' => 'Physical Inventory Product']],
    ));

    $this->warehouse = Warehouse::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'GEN-WH-01',
        'name' => 'Geneva Hub',
        'country_code' => 'CH',
    ]);

    $this->source = InventorySource::create([
        'tenant_id' => $this->tenant->id,
        'warehouse_id' => $this->warehouse->id,
        'code' => 'GEN-SRC-01',
        'name' => 'Geneva Hub Stock',
    ]);

    $this->stockItem = StockItem::create([
        'tenant_id' => $this->tenant->id,
        'inventory_source_id' => $this->source->id,
        'product_id' => $this->product->id,
        'on_hand' => '0.0000',
        'reserved' => '0.0000',
    ]);
});

test('InventoryAdjustmentService receives stock and emits InventoryReceived event', function (): void {
    Event::fake([InventoryReceived::class, InventoryAdjusted::class]);
    $service = app(InventoryAdjustmentServiceInterface::class);

    $movement = $service->receive(
        stockItem: $this->stockItem,
        quantity: Quantity::fromString('50.0000'),
        referenceType: 'purchase_order',
        referenceId: 'PO-2026-99'
    );

    expect($movement)->toBeInstanceOf(InventoryMovement::class)
        ->and($movement->quantity_delta)->toBe('50.0000')
        ->and($movement->resulting_on_hand)->toBe('50.0000')
        ->and($movement->movement_type)->toBe('receive');

    $this->stockItem->refresh();
    expect($this->stockItem->on_hand)->toBe('50.0000');

    Event::assertDispatched(InventoryReceived::class);
});

test('InventoryAdjustmentService rejects invalid arbitrary movement types', function (): void {
    $service = app(InventoryAdjustmentServiceInterface::class);

    expect(fn () => $service->adjust(
        stockItem: $this->stockItem,
        delta: Quantity::fromString('10.0000'),
        movementType: 'invalid_arbitrary_hack'
    ))->toThrow(InvalidArgumentException::class);
});

test('InventoryMovement is immutable and rejects update or delete', function (): void {
    $service = app(InventoryAdjustmentServiceInterface::class);
    $movement = $service->receive($this->stockItem, Quantity::fromString('10.0000'));

    expect(fn () => $movement->update(['reason' => 'Hacked reason']))->toThrow(LogicException::class);
    expect(fn () => $movement->delete())->toThrow(LogicException::class);
});

test('InventoryAdjustmentService supports condition bucket transitions', function (): void {
    $service = app(InventoryAdjustmentServiceInterface::class);
    $service->receive($this->stockItem, Quantity::fromString('100.0000'));

    // Quarantine 10
    $service->quarantine($this->stockItem, Quantity::fromString('10.0000'), 'Suspected batch defect');
    $this->stockItem->refresh();
    expect($this->stockItem->quarantined)->toBe('10.0000')
        ->and($this->stockItem->getAvailableToSellQuantity()->toString())->toBe('90.0000');

    // Release 5 from quarantine
    $service->releaseQuarantine($this->stockItem, Quantity::fromString('5.0000'), 'Defect cleared for half');
    $this->stockItem->refresh();
    expect($this->stockItem->quarantined)->toBe('5.0000')
        ->and($this->stockItem->getAvailableToSellQuantity()->toString())->toBe('95.0000');

    // Mark 3 damaged
    $service->markDamaged($this->stockItem, Quantity::fromString('3.0000'), 'Water damage');
    $this->stockItem->refresh();
    expect($this->stockItem->damaged)->toBe('3.0000')
        ->and($this->stockItem->getAvailableToSellQuantity()->toString())->toBe('92.0000');
});
