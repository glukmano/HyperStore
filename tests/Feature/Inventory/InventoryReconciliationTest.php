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
use Modules\Inventory\Services\InventoryReconciliationService;
use Modules\Inventory\ValueObjects\Quantity;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create(['slug' => 'reconcile-tenant', 'name' => 'Reconcile Tenant', 'status' => 'active']);

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'RECONCILE-SKU',
        translations: ['en' => ['name' => 'Reconcile Product']],
    ));

    $this->wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'REC-WH', 'name' => 'Rec Wh', 'country_code' => 'CH']);
    $this->src = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->wh->id, 'code' => 'REC-SRC', 'name' => 'Rec Src']);

    $this->stockItem = StockItem::create([
        'tenant_id' => $this->tenant->id,
        'inventory_source_id' => $this->src->id,
        'product_id' => $this->product->id,
        'on_hand' => '0.0000',
    ]);
});

test('InventoryReconciliationService detects drift accurately', function (): void {
    $adjService = app(InventoryAdjustmentServiceInterface::class);
    $recService = app(InventoryReconciliationService::class);

    $adjService->receive($this->stockItem, Quantity::fromString('25.0000'));
    $report1 = $recService->reconcile($this->tenant->id);

    expect($report1['is_clean'])->toBeTrue();

    // Introduce drift
    $this->stockItem->on_hand = '30.0000';
    $this->stockItem->save();

    $report2 = $recService->reconcile($this->tenant->id);
    expect($report2['is_clean'])->toBeFalse()
        ->and($report2['balance_discrepancies'][0]['drift'])->toBe('5.0000');
});
