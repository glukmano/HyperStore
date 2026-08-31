<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\ValueObjects\Quantity;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create(['slug' => 'concurrency-tenant', 'name' => 'Concurrency Tenant', 'status' => 'active']);

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'CONCURRENT-SKU-1',
        translations: ['en' => ['name' => 'Concurrent Item']],
    ));

    $this->wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH-CONC', 'name' => 'Conc Wh', 'country_code' => 'CH']);
    $this->src = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->wh->id, 'code' => 'SRC-CONC', 'name' => 'Conc Source']);

    // Exactly 1 unit in stock
    $this->stockItem = StockItem::create([
        'tenant_id' => $this->tenant->id,
        'inventory_source_id' => $this->src->id,
        'product_id' => $this->product->id,
        'on_hand' => '1.0000',
        'reserved' => '0.0000',
        'backorder_mode' => 'deny',
    ]);
});

test('Oversell protection guarantees only winning transaction reserves stock under pessimistic row locking', function (): void {
    $service = app(InventoryReservationServiceInterface::class);
    $context = new InventoryContext(tenantId: $this->tenant->id);

    // Transaction 1 locks the stock row with SELECT ... FOR UPDATE and reserves the 1 available unit
    $res1 = $service->reserve($this->tenant->id, 'tx-res-1', $this->product->id, null, Quantity::fromString('1.0000'), $context);
    expect($res1->isSuccess)->toBeTrue();

    // Transaction 2 immediately re-evaluates the stock under lock and must fail because ATS = 0
    $res2 = $service->reserve($this->tenant->id, 'tx-res-2', $this->product->id, null, Quantity::fromString('1.0000'), $context);
    expect($res2->isSuccess)->toBeFalse()
        ->and($res2->message)->toContain('Insufficient available stock');

    $this->stockItem->refresh();
    // Final reserved balance MUST be 1.0000 (never 2.0000)
    expect($this->stockItem->reserved)->toBe('1.0000');
});
