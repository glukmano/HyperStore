<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventoryOperationKey;
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

test('Claim-first idempotency prevents duplicate stock addition on retried calls', function (): void {
    $service = app(InventoryAdjustmentServiceInterface::class);

    // Initial receive: +15
    $m1 = $service->receive($this->stockItem, Quantity::fromString('15.0000'), idempotencyKey: 'RECEIVE-KEY-100');
    $this->stockItem->refresh();
    expect($this->stockItem->on_hand)->toBe('15.0000');

    // Duplicate call with identical key
    $m2 = $service->receive($this->stockItem, Quantity::fromString('15.0000'), idempotencyKey: 'RECEIVE-KEY-100');
    $this->stockItem->refresh();

    // Stock must remain 15.0000, not 30.0000
    expect($this->stockItem->on_hand)->toBe('15.0000')
        ->and($m1->id)->toBe($m2->id);

    // Verify exactly 1 movement and 1 operation key in database
    $movementsCount = InventoryMovement::where('stock_item_id', $this->stockItem->id)->count();
    $keysCount = InventoryOperationKey::where('idempotency_key', 'RECEIVE-KEY-100')->count();

    expect($movementsCount)->toBe(1)
        ->and($keysCount)->toBe(1);
});
