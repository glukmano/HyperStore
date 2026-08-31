<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\ValueObjects\Quantity;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create(['slug' => 'idempotency-tenant', 'name' => 'Idempotency Tenant', 'status' => 'active']);

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'IDEMPOTENT-SKU',
        translations: ['en' => ['name' => 'Idempotent Product']],
    ));

    $this->wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'IDEM-WH', 'name' => 'Idem Wh', 'country_code' => 'CH']);
    $this->src = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->wh->id, 'code' => 'IDEM-SRC', 'name' => 'Idem Src']);

    $this->stockItem = StockItem::create([
        'tenant_id' => $this->tenant->id,
        'inventory_source_id' => $this->src->id,
        'product_id' => $this->product->id,
        'on_hand' => '0.0000',
    ]);
});

test('Receiving with duplicate idempotency key does not duplicate stock', function (): void {
    $service = app(InventoryAdjustmentServiceInterface::class);

    $service->receive($this->stockItem, Quantity::fromString('15.0000'), idempotencyKey: 'RECEIVE-KEY-100');
    $this->stockItem->refresh();
    expect($this->stockItem->on_hand)->toBe('15.0000');

    $service->receive($this->stockItem, Quantity::fromString('15.0000'), idempotencyKey: 'RECEIVE-KEY-100');
    $this->stockItem->refresh();

    expect($this->stockItem->on_hand)->toBe('15.0000');
});
