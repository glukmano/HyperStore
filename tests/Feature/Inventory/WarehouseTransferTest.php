<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use InvalidArgumentException;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\InventoryTransferItem;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create(['slug' => 'transfer-test-tenant', 'name' => 'Transfer Test Tenant', 'status' => 'active']);

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'TRANSFER-SKU',
        translations: ['en' => ['name' => 'Transfer Product']],
    ));

    $this->sourceWh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'TR-SRC-WH', 'name' => 'Source Wh', 'country_code' => 'CH']);
    $this->destWh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'TR-DEST-WH', 'name' => 'Dest Wh', 'country_code' => 'CH']);

    $this->sourceSrc = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->sourceWh->id, 'code' => 'TR-SRC-S', 'name' => 'Source S']);
    $this->destSrc = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->destWh->id, 'code' => 'TR-DEST-S', 'name' => 'Dest S']);

    // Source WH has 20 units
    $this->sourceStock = StockItem::create([
        'tenant_id' => $this->tenant->id,
        'inventory_source_id' => $this->sourceSrc->id,
        'product_id' => $this->product->id,
        'on_hand' => '20.0000',
    ]);
});

test('Warehouse transfer flow supports cumulative multi-step partial receiving', function (): void {
    $service = app(InventoryTransferServiceInterface::class);

    $transfer = InventoryTransfer::create([
        'tenant_id' => $this->tenant->id,
        'transfer_number' => 'TR-2026-001',
        'source_inventory_source_id' => $this->sourceSrc->id,
        'destination_inventory_source_id' => $this->destSrc->id,
        'source_warehouse_id' => $this->sourceWh->id,
        'destination_warehouse_id' => $this->destWh->id,
        'status' => 'requested',
    ]);

    $item = InventoryTransferItem::create([
        'inventory_transfer_id' => $transfer->id,
        'product_id' => $this->product->id,
        'requested_quantity' => '10.0000',
    ]);

    // 1. Dispatch 10 units
    $dispatched = $service->dispatch($transfer);
    expect($dispatched)->toBeTrue();

    $this->sourceStock->refresh();
    expect($this->sourceStock->on_hand)->toBe('10.0000');

    // 2. Reject Over-receipt (11 > 10)
    expect(fn () => $service->receive($transfer, [$item->id => '11.0000']))
        ->toThrow(InvalidArgumentException::class);

    // 3. Receive #1: 4 units -> partially_received
    $service->receive($transfer, [$item->id => '4.0000']);
    $transfer->refresh();
    $item->refresh();

    $destStock = StockItem::where('inventory_source_id', $this->destSrc->id)->first();
    expect($transfer->status)->toBe('partially_received')
        ->and($item->received_quantity)->toBe('4.0000')
        ->and($destStock->on_hand)->toBe('4.0000');

    // 4. Receive #2: 3 units -> cumulative 7, partially_received
    $service->receive($transfer, [$item->id => '3.0000']);
    $transfer->refresh();
    $item->refresh();
    $destStock->refresh();

    expect($transfer->status)->toBe('partially_received')
        ->and($item->received_quantity)->toBe('7.0000')
        ->and($destStock->on_hand)->toBe('7.0000');

    // 5. Receive #3: remaining 3 units -> cumulative 10, received
    $service->receive($transfer, [$item->id => '3.0000']);
    $transfer->refresh();
    $item->refresh();
    $destStock->refresh();

    expect($transfer->status)->toBe('received')
        ->and($item->received_quantity)->toBe('10.0000')
        ->and($destStock->on_hand)->toBe('10.0000');

    // 6. Further receipt after fully received throws InvalidArgumentException
    expect(fn () => $service->receive($transfer, [$item->id => '1.0000']))
        ->toThrow(InvalidArgumentException::class);
});
