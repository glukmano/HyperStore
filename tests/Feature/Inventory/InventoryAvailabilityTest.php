<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Contracts\InventoryAvailabilityServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create(['slug' => 'ats-test-tenant', 'name' => 'ATS Test Tenant', 'status' => 'active']);

    $this->physicalProduct = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'PHYS-ATS-SKU',
        translations: ['en' => ['name' => 'Physical ATS Product']],
    ));

    $this->digitalProduct = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'digital',
        sku: 'DIGI-ATS-SKU',
        translations: ['en' => ['name' => 'Digital eBook Product']],
    ));

    $this->whA = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH-A', 'name' => 'Warehouse A', 'country_code' => 'CH']);
    $this->whB = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH-B', 'name' => 'Warehouse B', 'country_code' => 'CH']);

    $this->srcA = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->whA->id, 'code' => 'SRC-A', 'name' => 'Source A', 'priority' => 10]);
    $this->srcB = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->whB->id, 'code' => 'SRC-B', 'name' => 'Source B', 'priority' => 5]);
});

test('InventoryAvailabilityService aggregates ATS across multiple eligible sources', function (): void {
    // Source A: on_hand = 10, reserved = 2 -> ATS = 8
    StockItem::create([
        'tenant_id' => $this->tenant->id,
        'inventory_source_id' => $this->srcA->id,
        'product_id' => $this->physicalProduct->id,
        'on_hand' => '10.0000',
        'reserved' => '2.0000',
    ]);

    // Source B: on_hand = 15, quarantined = 5 -> ATS = 10
    StockItem::create([
        'tenant_id' => $this->tenant->id,
        'inventory_source_id' => $this->srcB->id,
        'product_id' => $this->physicalProduct->id,
        'on_hand' => '15.0000',
        'quarantined' => '5.0000',
        'reserved' => '0.0000',
    ]);

    $service = app(InventoryAvailabilityServiceInterface::class);
    $result = $service->check($this->physicalProduct->id, null, new InventoryContext(tenantId: $this->tenant->id));

    // Aggregate ATS: 8 + 10 = 18
    expect($result->availableQuantity->toString())->toBe('18.0000')
        ->and($result->isInStock)->toBeTrue()
        ->and($result->sourceBreakdown)->toHaveCount(2);
});

test('InventoryAvailabilityService returns untracked for non-inventory digital products', function (): void {
    $service = app(InventoryAvailabilityServiceInterface::class);
    $result = $service->check($this->digitalProduct->id, null, new InventoryContext(tenantId: $this->tenant->id));

    expect($result->stockStatus)->toBe('untracked')
        ->and($result->isInStock)->toBeTrue()
        ->and($result->isBackorderable)->toBeTrue();
});
