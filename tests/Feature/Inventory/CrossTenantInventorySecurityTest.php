<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Contracts\InventoryAvailabilityServiceInterface;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\ValueObjects\Quantity;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenantA = Tenant::create(['name' => 'Tenant A Inventory', 'slug' => 'tenant-a-inv', 'status' => 'active']);
    $this->tenantB = Tenant::create(['name' => 'Tenant B Inventory', 'slug' => 'tenant-b-inv', 'status' => 'active']);

    $this->productA = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenantA->id,
        productType: 'physical',
        sku: 'PROD-TENANT-A-INV',
        translations: ['en' => ['name' => 'Tenant A Product']],
    ));

    $this->whB = Warehouse::create(['tenant_id' => $this->tenantB->id, 'code' => 'WH-TENANT-B', 'name' => 'Tenant B Wh', 'country_code' => 'CH']);
    $this->srcB = InventorySource::create(['tenant_id' => $this->tenantB->id, 'warehouse_id' => $this->whB->id, 'code' => 'SRC-TENANT-B', 'name' => 'Tenant B Src']);

    // Tenant B has stock of Product A
    StockItem::create([
        'tenant_id' => $this->tenantB->id,
        'inventory_source_id' => $this->srcB->id,
        'product_id' => $this->productA->id,
        'on_hand' => '100.0000',
    ]);
});

test('Tenant A cannot view or reserve Tenant B stock', function (): void {
    $availService = app(InventoryAvailabilityServiceInterface::class);
    $resService = app(InventoryReservationServiceInterface::class);

    // Tenant A context
    $contextA = new InventoryContext(tenantId: $this->tenantA->id);

    $avail = $availService->check($this->productA->id, null, $contextA);
    expect($avail->availableQuantity->toString())->toBe('0.0000')
        ->and($avail->isInStock)->toBeFalse();

    $res = $resService->reserve('res-tenant-a-attack', $this->productA->id, null, Quantity::fromString('5.0000'), $contextA);
    expect($res->isSuccess)->toBeFalse();
});
