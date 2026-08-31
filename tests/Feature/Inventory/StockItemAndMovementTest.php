<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\ValueObjects\Quantity;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'stock-move-tenant'],
        ['name' => 'Stock Movement Tenant', 'status' => 'active']
    );

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

test('InventoryAdjustmentService receives stock and logs immutable movement', function (): void {
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
});

test('InventoryAdjustmentService applies adjustments with positive and negative deltas', function (): void {
    $service = app(InventoryAdjustmentServiceInterface::class);

    // Initial receive: 100
    $service->receive($this->stockItem, Quantity::fromString('100.0000'));

    // Damage adjustment: -5
    $service->adjust(
        stockItem: $this->stockItem,
        delta: Quantity::fromString('-5.0000'),
        movementType: 'damaged',
        reason: 'Broken in warehouse transit'
    );

    $this->stockItem->refresh();
    expect($this->stockItem->on_hand)->toBe('95.0000');

    // Audit movements count = 2
    $movements = InventoryMovement::where('stock_item_id', $this->stockItem->id)->get();
    expect($movements)->toHaveCount(2);
});
