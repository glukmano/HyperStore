<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Dropshipping\Adapters\SupplierExternalStockProvider;
use Modules\Dropshipping\Models\Supplier;
use Modules\Dropshipping\Models\SupplierLocation;
use Modules\Dropshipping\Models\SupplierOffer;
use Modules\Dropshipping\Models\SupplierProductVariant;
use Modules\Inventory\Contracts\ExternalStockProviderInterface;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\Contracts\InventorySourceQueryInterface;
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\DTOs\SourceAvailabilityDTO;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\ValueObjects\Quantity;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Exceptions\VendorOperationalStatusException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorPlan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create(['slug' => 'p14-tenant-'.uniqid(), 'name' => 'Phase14 Tenant', 'status' => 'active']);

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'P14-SKU-'.uniqid(),
        translations: ['en' => ['name' => 'Phase14 Product']],
    ));

    $this->preorderProduct = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'preorder',
        sku: 'P14-PREORDER-'.uniqid(),
        translations: ['en' => ['name' => 'Phase14 Preorder Product']],
    ));
});

// ═══════════════════════════════════════════════════════════════════
// Warehouse taxonomy + Vendor ownership (ADR-0122, ADR-0123)
// ═══════════════════════════════════════════════════════════════════

test('warehouse ownership_type vendor requires vendor_id', function (): void {
    expect(fn () => Warehouse::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'WH-A',
        'name' => 'A',
        'country_code' => 'CH',
        'ownership_type' => 'vendor',
    ]))->toThrow(InvalidArgumentException::class);
});

test('warehouse vendor_id may only be set when ownership_type is vendor', function (): void {
    $plan = VendorPlan::create(['tenant_id' => $this->tenant->id, 'name' => 'P', 'code' => 'p14-plan-'.uniqid()]);
    $vendor = Vendor::create([
        'tenant_id' => $this->tenant->id,
        'vendor_plan_id' => $plan->id,
        'name' => 'V', 'platform_slug' => 'v-'.uniqid(), 'legal_name' => 'V Corp',
        'email' => 'v@example.com', 'payout_currency' => 'EUR',
        'operational_status' => VendorOperationalStatus::Active->value,
    ]);

    expect(fn () => Warehouse::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'WH-B',
        'name' => 'B',
        'country_code' => 'CH',
        'ownership_type' => 'platform',
        'vendor_id' => $vendor->id,
    ]))->toThrow(InvalidArgumentException::class);
});

test('vendor-owned warehouse mutation is blocked when vendor is suspended', function (): void {
    $plan = VendorPlan::create(['tenant_id' => $this->tenant->id, 'name' => 'P', 'code' => 'p14-plan-'.uniqid()]);
    $vendor = Vendor::create([
        'tenant_id' => $this->tenant->id,
        'vendor_plan_id' => $plan->id,
        'name' => 'V', 'platform_slug' => 'v-'.uniqid(), 'legal_name' => 'V Corp',
        'email' => 'v@example.com', 'payout_currency' => 'EUR',
        'operational_status' => VendorOperationalStatus::Suspended->value,
    ]);

    $wh = Warehouse::create([
        'tenant_id' => $this->tenant->id, 'code' => 'WH-SUSP', 'name' => 'Suspended WH',
        'country_code' => 'CH', 'ownership_type' => 'vendor', 'vendor_id' => $vendor->id,
    ]);
    $src = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC-SUSP', 'name' => 'S']);

    expect(fn () => app(InventoryTransferServiceInterface::class)->create(
        tenantId: $this->tenant->id,
        sourceInventorySourceId: $src->id,
        destinationInventorySourceId: InventorySource::create(['tenant_id' => $this->tenant->id, 'code' => 'DEST-SUSP', 'name' => 'D'])->id,
        transferNumber: 'TR-SUSP',
        items: [['product_id' => $this->product->id, 'requested_quantity' => '1.0000']],
    ))->toThrow(VendorOperationalStatusException::class);
});

// ═══════════════════════════════════════════════════════════════════
// Formal InventoryTransfer::create() (ADR-0125)
// ═══════════════════════════════════════════════════════════════════

test('create() atomically materializes transfer header and items', function (): void {
    [$srcId, $destId] = makeSourcePair($this->tenant->id);
    StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $srcId, 'product_id' => $this->product->id, 'on_hand' => '10.0000']);

    $transfer = app(InventoryTransferServiceInterface::class)->create(
        tenantId: $this->tenant->id,
        sourceInventorySourceId: $srcId,
        destinationInventorySourceId: $destId,
        transferNumber: 'TR-CREATE-1',
        items: [['product_id' => $this->product->id, 'requested_quantity' => '5.0000']],
    );

    expect($transfer->status)->toBe('draft')
        ->and($transfer->items)->toHaveCount(1)
        ->and($transfer->items->first()->tenant_id)->toBe($this->tenant->id);
});

test('create() rejects duplicate lines for the same product/variant', function (): void {
    [$srcId, $destId] = makeSourcePair($this->tenant->id);
    StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $srcId, 'product_id' => $this->product->id, 'on_hand' => '10.0000']);

    expect(fn () => app(InventoryTransferServiceInterface::class)->create(
        tenantId: $this->tenant->id,
        sourceInventorySourceId: $srcId,
        destinationInventorySourceId: $destId,
        transferNumber: 'TR-DUP',
        items: [
            ['product_id' => $this->product->id, 'requested_quantity' => '1.0000'],
            ['product_id' => $this->product->id, 'requested_quantity' => '2.0000'],
        ],
    ))->toThrow(InvalidArgumentException::class);
});

test('create() rejects a quantity with more than 4 decimal places', function (): void {
    [$srcId, $destId] = makeSourcePair($this->tenant->id);

    expect(fn () => app(InventoryTransferServiceInterface::class)->create(
        tenantId: $this->tenant->id,
        sourceInventorySourceId: $srcId,
        destinationInventorySourceId: $destId,
        transferNumber: 'TR-SCALE',
        items: [['product_id' => $this->product->id, 'requested_quantity' => '1.00001']],
    ))->toThrow(InvalidArgumentException::class);
});

test('create() fails closed against an inactive source', function (): void {
    [$srcId, $destId] = makeSourcePair($this->tenant->id);
    InventorySource::where('id', $srcId)->update(['status' => 'inactive']);

    expect(fn () => app(InventoryTransferServiceInterface::class)->create(
        tenantId: $this->tenant->id,
        sourceInventorySourceId: $srcId,
        destinationInventorySourceId: $destId,
        transferNumber: 'TR-INACTIVE',
        items: [['product_id' => $this->product->id, 'requested_quantity' => '1.0000']],
    ))->toThrow(InvalidArgumentException::class);
});

test('create() same idempotency key and same payload replays the identical transfer', function (): void {
    [$srcId, $destId] = makeSourcePair($this->tenant->id);
    $key = 'idem-'.uniqid();

    $t1 = app(InventoryTransferServiceInterface::class)->create(
        tenantId: $this->tenant->id, sourceInventorySourceId: $srcId, destinationInventorySourceId: $destId,
        transferNumber: 'TR-IDEM', items: [['product_id' => $this->product->id, 'requested_quantity' => '2.0000']],
        idempotencyKey: $key,
    );

    $t2 = app(InventoryTransferServiceInterface::class)->create(
        tenantId: $this->tenant->id, sourceInventorySourceId: $srcId, destinationInventorySourceId: $destId,
        transferNumber: 'TR-IDEM', items: [['product_id' => $this->product->id, 'requested_quantity' => '2.0000']],
        idempotencyKey: $key,
    );

    expect($t2->id)->toBe($t1->id);
});

// ═══════════════════════════════════════════════════════════════════
// Transfer conservation equations + incoming activation (ADR-0125)
// ═══════════════════════════════════════════════════════════════════

test('dispatch activates destination incoming and receive resolves it to on_hand', function (): void {
    [$srcId, $destId] = makeSourcePair($this->tenant->id);
    StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $srcId, 'product_id' => $this->product->id, 'on_hand' => '10.0000']);

    $service = app(InventoryTransferServiceInterface::class);
    $transfer = $service->create(
        tenantId: $this->tenant->id, sourceInventorySourceId: $srcId, destinationInventorySourceId: $destId,
        transferNumber: 'TR-CONS', items: [['product_id' => $this->product->id, 'requested_quantity' => '6.0000']],
    );

    $service->dispatch($transfer);

    $destStock = StockItem::where('inventory_source_id', $destId)->where('product_id', $this->product->id)->first();
    expect($destStock->incoming)->toBe('6.0000')
        ->and($destStock->on_hand)->toBe('0.0000')
        ->and($destStock->getAvailableToSellQuantity()->toString())->toBe('0.0000');

    expect(InventoryMovement::where('movement_type', 'transfer_pending_in')->where('inventory_source_id', $destId)->exists())->toBeTrue();

    $service->receive($transfer);

    $destStock->refresh();
    expect($destStock->incoming)->toBe('0.0000')
        ->and($destStock->on_hand)->toBe('6.0000');
});

test('receive() supports damaged and quarantine disposition breakdown', function (): void {
    [$srcId, $destId] = makeSourcePair($this->tenant->id);
    StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $srcId, 'product_id' => $this->product->id, 'on_hand' => '10.0000']);

    $service = app(InventoryTransferServiceInterface::class);
    $transfer = $service->create(
        tenantId: $this->tenant->id, sourceInventorySourceId: $srcId, destinationInventorySourceId: $destId,
        transferNumber: 'TR-DISP', items: [['product_id' => $this->product->id, 'requested_quantity' => '9.0000']],
    );
    $service->dispatch($transfer);

    $item = $transfer->items->first();
    $service->receive($transfer, [$item->id => ['good' => '5.0000', 'damaged' => '2.0000', 'quarantine' => '2.0000']]);

    $destStock = StockItem::where('inventory_source_id', $destId)->where('product_id', $this->product->id)->first();
    expect($destStock->on_hand)->toBe('5.0000')
        ->and($destStock->damaged)->toBe('2.0000')
        ->and($destStock->quarantined)->toBe('2.0000')
        ->and($destStock->incoming)->toBe('0.0000');

    $transfer->refresh();
    expect($transfer->status)->toBe('received');
});

test('dispatch() respects reserved stock and rejects taking reserved quantity', function (): void {
    [$srcId, $destId] = makeSourcePair($this->tenant->id);
    StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $srcId, 'product_id' => $this->product->id, 'on_hand' => '5.0000', 'reserved' => '0.0000']);

    // Reserve 3 of the 5 units for a Checkout
    app(InventoryReservationServiceInterface::class)->reserve(
        $this->tenant->id, 'res-'.uniqid(), $this->product->id, null, Quantity::fromString('3.0000'),
        new InventoryContext(tenantId: $this->tenant->id), 60
    );

    $service = app(InventoryTransferServiceInterface::class);
    $transfer = $service->create(
        tenantId: $this->tenant->id, sourceInventorySourceId: $srcId, destinationInventorySourceId: $destId,
        transferNumber: 'TR-RESV', items: [['product_id' => $this->product->id, 'requested_quantity' => '4.0000']],
    );

    // Only 2 units are available-to-sell (5 on_hand - 3 reserved) — dispatching 4 must fail.
    expect(fn () => $service->dispatch($transfer))->toThrow(InvalidArgumentException::class);
});

// ═══════════════════════════════════════════════════════════════════
// Preorder readiness ownership correction (ADR-0127)
// ═══════════════════════════════════════════════════════════════════

test('preorder readiness is derived from Catalog product_type, not a phantom Inventory flag', function (): void {
    [$srcId] = makeSourcePair($this->tenant->id);
    StockItem::create([
        'tenant_id' => $this->tenant->id, 'inventory_source_id' => $srcId,
        'product_id' => $this->preorderProduct->id, 'on_hand' => '0.0000', 'backorder_mode' => 'deny',
    ]);

    $dto = app(InventorySourceQueryInterface::class)->checkSourceAvailability(
        $this->preorderProduct->id, null, $srcId, new InventoryContext(tenantId: $this->tenant->id)
    );

    expect($dto->readiness)->toBe(SourceAvailabilityDTO::PREORDER);
});

test('non-preorder product with zero stock and deny backorder is unavailable, not preorder', function (): void {
    [$srcId] = makeSourcePair($this->tenant->id);
    StockItem::create([
        'tenant_id' => $this->tenant->id, 'inventory_source_id' => $srcId,
        'product_id' => $this->product->id, 'on_hand' => '0.0000', 'backorder_mode' => 'deny',
    ]);

    $dto = app(InventorySourceQueryInterface::class)->checkSourceAvailability(
        $this->product->id, null, $srcId, new InventoryContext(tenantId: $this->tenant->id)
    );

    expect($dto->readiness)->toBe(SourceAvailabilityDTO::UNAVAILABLE);
});

// ═══════════════════════════════════════════════════════════════════
// External Supplier Stock SPI (ADR-0124) — read-only, never on_hand
// ═══════════════════════════════════════════════════════════════════

test('external supplier stock is surfaced read-only and never written to on_hand', function (): void {
    $supplier = Supplier::create([
        'scope_type' => 'tenant', 'tenant_id' => $this->tenant->id, 'code' => 'SUP-'.uniqid(),
        'name' => 'Ext Supplier', 'contact_email' => 'sup@example.com', 'status' => 'active', 'currency' => 'EUR',
    ]);
    $spv = SupplierProductVariant::create([
        'tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'product_id' => $this->product->id,
        'supplier_sku' => 'SSKU-1', 'canonical_wholesale_cost_minor' => 500, 'currency' => 'EUR',
    ]);
    $location = SupplierLocation::create([
        'supplier_id' => $supplier->id, 'code' => 'LOC-1', 'name' => 'Loc 1', 'country_code' => 'CH', 'city' => 'Zurich', 'postal_code' => '8000', 'address_line1' => 'Street 1',
    ]);
    $offer = SupplierOffer::create([
        'supplier_id' => $supplier->id, 'supplier_product_variant_id' => $spv->id, 'supplier_location_id' => $location->id,
        'stock_quantity' => '42.00000000', 'is_available' => true, 'location_wholesale_cost_minor' => 500,
    ]);

    $source = InventorySource::create([
        'tenant_id' => $this->tenant->id, 'code' => 'EXT-SRC', 'name' => 'External',
        'source_type' => 'supplier', 'external_reference' => 'supplier_offer:'.$offer->id,
    ]);

    $snapshot = app(ExternalStockProviderInterface::class)->getAvailability($source);

    expect($snapshot->unavailable)->toBeFalse()
        ->and($snapshot->available->toString())->toBe('42.0000');

    // Never written into stock_items.on_hand
    expect(StockItem::where('inventory_source_id', $source->id)->exists())->toBeFalse();
});

test('external supplier stock fails closed when offer is unavailable', function (): void {
    $supplier = Supplier::create([
        'scope_type' => 'tenant', 'tenant_id' => $this->tenant->id, 'code' => 'SUP-'.uniqid(),
        'name' => 'Ext Supplier', 'contact_email' => 'sup@example.com', 'status' => 'active', 'currency' => 'EUR',
    ]);
    $spv = SupplierProductVariant::create([
        'tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'product_id' => $this->product->id,
        'supplier_sku' => 'SSKU-2', 'canonical_wholesale_cost_minor' => 500, 'currency' => 'EUR',
    ]);
    $location = SupplierLocation::create([
        'supplier_id' => $supplier->id, 'code' => 'LOC-2', 'name' => 'Loc 2', 'country_code' => 'CH', 'city' => 'Zurich', 'postal_code' => '8000', 'address_line1' => 'Street 1',
    ]);
    $offer = SupplierOffer::create([
        'supplier_id' => $supplier->id, 'supplier_product_variant_id' => $spv->id, 'supplier_location_id' => $location->id,
        'stock_quantity' => '10.00000000', 'is_available' => false, 'location_wholesale_cost_minor' => 500,
    ]);
    $source = InventorySource::create([
        'tenant_id' => $this->tenant->id, 'code' => 'EXT-SRC-2', 'name' => 'External 2',
        'source_type' => 'supplier', 'external_reference' => 'supplier_offer:'.$offer->id,
    ]);

    $snapshot = app(SupplierExternalStockProvider::class)->getAvailability($source);

    expect($snapshot->unavailable)->toBeTrue()
        ->and($snapshot->available)->toBeNull();
});

function makeSourcePair(int $tenantId): array
{
    $uid = uniqid('src_');
    $whA = Warehouse::create(['tenant_id' => $tenantId, 'code' => 'WA-'.$uid, 'name' => 'WA', 'country_code' => 'CH']);
    $whB = Warehouse::create(['tenant_id' => $tenantId, 'code' => 'WB-'.$uid, 'name' => 'WB', 'country_code' => 'CH']);
    $srcA = InventorySource::create(['tenant_id' => $tenantId, 'warehouse_id' => $whA->id, 'code' => 'SA-'.$uid, 'name' => 'SA']);
    $srcB = InventorySource::create(['tenant_id' => $tenantId, 'warehouse_id' => $whB->id, 'code' => 'SB-'.$uid, 'name' => 'SB']);

    return [$srcA->id, $srcB->id];
}
