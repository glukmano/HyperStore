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

test('Warehouse transfer flow dispatches stock and rejects over-receipt', function (): void {
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

    // 1. Dispatch
    $dispatched = $service->dispatch($transfer);
    expect($dispatched)->toBeTrue();

    $this->sourceStock->refresh();
    expect($this->sourceStock->on_hand)->toBe('10.0000');

    // 2. Reject Over-receipt (15 > 10)
    expect(fn () => $service->receive($transfer, [$item->id => '15.0000']))
        ->toThrow(InvalidArgumentException::class);

    // 3. Valid Partial Receipt (8 of 10)
    $received = $service->receive($transfer, [$item->id => '8.0000']);
    expect($received)->toBeTrue();

    $destStock = StockItem::where('inventory_source_id', $this->destSrc->id)->first();
    expect($destStock->on_hand)->toBe('8.0000');
});
