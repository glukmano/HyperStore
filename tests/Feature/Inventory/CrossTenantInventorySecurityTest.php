<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use InvalidArgumentException;
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
});

test('StockItem creation rejects mismatched product tenant_id', function (): void {
    // Attempting to attach Tenant A Product to Tenant B StockItem must throw InvalidArgumentException
    expect(fn () => StockItem::create([
        'tenant_id' => $this->tenantB->id,
        'inventory_source_id' => $this->srcB->id,
        'product_id' => $this->productA->id,
        'on_hand' => '100.0000',
    ]))->toThrow(InvalidArgumentException::class);
});

test('Tenant A cannot view or reserve Tenant B stock', function (): void {
    $productB = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenantB->id,
        productType: 'physical',
        sku: 'PROD-TENANT-B-INV',
        translations: ['en' => ['name' => 'Tenant B Product']],
    ));

    StockItem::create([
        'tenant_id' => $this->tenantB->id,
        'inventory_source_id' => $this->srcB->id,
        'product_id' => $productB->id,
        'on_hand' => '50.0000',
    ]);

    $availService = app(InventoryAvailabilityServiceInterface::class);
    $resService = app(InventoryReservationServiceInterface::class);

    $contextA = new InventoryContext(tenantId: $this->tenantA->id);

    $avail = $availService->check($productB->id, null, $contextA);
    expect($avail->availableQuantity->toString())->toBe('0.0000')
        ->and($avail->isInStock)->toBeFalse();

    $res = $resService->reserve($this->tenantA->id, 'res-tenant-a-attack', $productB->id, null, Quantity::fromString('5.0000'), $contextA);
    expect($res->isSuccess)->toBeFalse();
});
