<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use Carbon\Carbon;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Event;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Inventory\Commands\ExpireReservationsCommand;
use Modules\Inventory\Contracts\InventoryReservationServiceInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Inventory\Enums\ReservationOwnerType;
use Modules\Inventory\Events\InventoryReservationAdopted;
use Modules\Inventory\Events\InventoryReservationReleased;
use Modules\Inventory\Exceptions\ReservationAdoptionException;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\ValueObjects\Quantity;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create([
        'slug' => 'adoption-tenant',
        'name' => 'Adoption Test Tenant',
        'status' => 'active',
    ]);

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'ADOPT-SKU-001',
        translations: ['en' => ['name' => 'Adoption Product']],
    ));

    $wh = Warehouse::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'WH-ADOPT',
        'name' => 'Adoption WH',
        'country_code' => 'CH',
    ]);

    $src = InventorySource::create([
        'tenant_id' => $this->tenant->id,
        'warehouse_id' => $wh->id,
        'code' => 'SRC-ADOPT',
        'name' => 'Adoption Source',
        'priority' => 10,
    ]);

    $this->stockItem = StockItem::create([
        'tenant_id' => $this->tenant->id,
        'inventory_source_id' => $src->id,
        'product_id' => $this->product->id,
        'on_hand' => '10.0000',
        'reserved' => '0.0000',
    ]);

    $this->service = app(InventoryReservationServiceInterface::class);
    $this->context = new InventoryContext(tenantId: $this->tenant->id);
});

// ---------------------------------------------------------------------------
// A-01: Successful adoption sets ownership, clears expires_at, fires event
// ---------------------------------------------------------------------------
test('A-01: successful adopt sets owner fields, clears expires_at, dispatches InventoryReservationAdopted', function (): void {
    Event::fake([InventoryReservationAdopted::class]);

    $this->service->reserve(
        $this->tenant->id,
        'chk-adopt-a01',
        $this->product->id,
        null,
        Quantity::fromString('2.0000'),
        $this->context,
        ttlMinutes: 60,
    );

    $result = $this->service->adopt(
        $this->tenant->id,
        'chk-adopt-a01',
        ReservationOwnerType::ORDER,
        'ORDER-UUID-001',
    );

    expect($result->isSuccess)->toBeTrue()
        ->and($result->wasAlreadyAdopted)->toBeFalse()
        ->and($result->reservation)->not->toBeNull()
        ->and($result->reservation->status)->toBe('active')
        ->and($result->reservation->owner_type)->toBe('order')
        ->and($result->reservation->owner_reference)->toBe('ORDER-UUID-001')
        ->and($result->reservation->adopted_at)->not->toBeNull()
        ->and($result->reservation->expires_at)->toBeNull();

    Event::assertDispatched(InventoryReservationAdopted::class, function ($e) {
        return $e->reservation->reservation_key === 'chk-adopt-a01';
    });
});

// ---------------------------------------------------------------------------
// A-02: Allocations unchanged during adoption; StockItem.reserved unchanged
// ---------------------------------------------------------------------------
test('A-02: adopt does not alter allocations or StockItem.reserved', function (): void {
    $this->service->reserve(
        $this->tenant->id,
        'chk-adopt-a02',
        $this->product->id,
        null,
        Quantity::fromString('3.0000'),
        $this->context,
    );

    $reservedBefore = $this->stockItem->fresh()->reserved;

    $this->service->adopt(
        $this->tenant->id,
        'chk-adopt-a02',
        ReservationOwnerType::ORDER,
        'ORDER-UUID-002',
    );

    $reservedAfter = $this->stockItem->fresh()->reserved;
    expect($reservedAfter)->toBe($reservedBefore);

    $res = InventoryReservation::where('reservation_key', 'chk-adopt-a02')->first();
    expect($res->allocations)->not->toBeEmpty();
});

// ---------------------------------------------------------------------------
// A-03: Adopted reservations are excluded from ExpireReservationsCommand
// ---------------------------------------------------------------------------
test('A-03: adopted reservation is not expired by ExpireReservationsCommand', function (): void {
    $this->service->reserve(
        $this->tenant->id,
        'chk-adopt-a03',
        $this->product->id,
        null,
        Quantity::fromString('1.0000'),
        $this->context,
        ttlMinutes: 60,
    );

    $this->service->adopt(
        $this->tenant->id,
        'chk-adopt-a03',
        ReservationOwnerType::ORDER,
        'ORDER-UUID-003',
    );

    // Simulate: manually set expires_at in the past (belt-and-suspenders; normally null after adopt)
    InventoryReservation::where('reservation_key', 'chk-adopt-a03')
        ->update(['expires_at' => Carbon::now()->subHour()]);

    $this->artisan(ExpireReservationsCommand::class);

    $res = InventoryReservation::where('reservation_key', 'chk-adopt-a03')->first();
    expect($res->status)->toBe('active');
});

// ---------------------------------------------------------------------------
// A-04: Unadopted active reservation with passed expires_at IS expired by command
// ---------------------------------------------------------------------------
test('A-04: unadopted active reservation with past expires_at is expired by ExpireReservationsCommand', function (): void {
    $this->service->reserve(
        $this->tenant->id,
        'chk-adopt-a04',
        $this->product->id,
        null,
        Quantity::fromString('1.0000'),
        $this->context,
        ttlMinutes: 60,
    );

    InventoryReservation::where('reservation_key', 'chk-adopt-a04')
        ->update(['expires_at' => Carbon::now()->subMinutes(5)]);

    $this->artisan(ExpireReservationsCommand::class);

    $res = InventoryReservation::where('reservation_key', 'chk-adopt-a04')->first();
    expect($res->status)->toBe('expired');
});

// ---------------------------------------------------------------------------
// A-05: Idempotent replay — same owner returns wasAlreadyAdopted=true
// ---------------------------------------------------------------------------
test('A-05: idempotent adopt returns wasAlreadyAdopted=true for same owner', function (): void {
    $this->service->reserve(
        $this->tenant->id,
        'chk-adopt-a05',
        $this->product->id,
        null,
        Quantity::fromString('1.0000'),
        $this->context,
    );

    $first = $this->service->adopt($this->tenant->id, 'chk-adopt-a05', ReservationOwnerType::ORDER, 'ORDER-IDEMPOTENT');
    $second = $this->service->adopt($this->tenant->id, 'chk-adopt-a05', ReservationOwnerType::ORDER, 'ORDER-IDEMPOTENT');

    expect($first->isSuccess)->toBeTrue()
        ->and($first->wasAlreadyAdopted)->toBeFalse()
        ->and($second->isSuccess)->toBeTrue()
        ->and($second->wasAlreadyAdopted)->toBeTrue();

    // StockItem.reserved must not change on replay
    expect($this->stockItem->fresh()->reserved)->toBe('1.0000');
});

// ---------------------------------------------------------------------------
// A-06: TTL-expired active reservation is REJECTED by adopt()
// ---------------------------------------------------------------------------
test('A-06: active reservation with past expires_at is rejected by adopt() with ReservationAdoptionException', function (): void {
    $this->service->reserve(
        $this->tenant->id,
        'chk-adopt-a06',
        $this->product->id,
        null,
        Quantity::fromString('1.0000'),
        $this->context,
        ttlMinutes: 60,
    );

    // Force expires_at into the past (simulates cleanup command not yet having run)
    InventoryReservation::where('reservation_key', 'chk-adopt-a06')
        ->update(['expires_at' => Carbon::now()->subMinutes(5)]);

    expect(fn () => $this->service->adopt(
        $this->tenant->id,
        'chk-adopt-a06',
        ReservationOwnerType::ORDER,
        'ORDER-SHOULD-FAIL',
    ))->toThrow(ReservationAdoptionException::class, 'RESERVATION_TTL_EXPIRED');

    // owner_type must remain null — reservation not adopted
    $res = InventoryReservation::where('reservation_key', 'chk-adopt-a06')->first();
    expect($res->owner_type)->toBeNull()
        ->and($res->owner_reference)->toBeNull()
        ->and($res->status)->toBe('active'); // ExpireReservationsCommand hasn't run

    // StockItem.reserved unchanged
    expect($this->stockItem->fresh()->reserved)->toBe('1.0000');
});

// ---------------------------------------------------------------------------
// A-07: Conflicting owner is rejected with exception
// ---------------------------------------------------------------------------
test('A-07: conflicting owner reference throws ReservationAdoptionException', function (): void {
    $this->service->reserve(
        $this->tenant->id,
        'chk-adopt-a07',
        $this->product->id,
        null,
        Quantity::fromString('1.0000'),
        $this->context,
    );

    $this->service->adopt($this->tenant->id, 'chk-adopt-a07', ReservationOwnerType::ORDER, 'ORDER-AAA');

    expect(fn () => $this->service->adopt(
        $this->tenant->id,
        'chk-adopt-a07',
        ReservationOwnerType::ORDER,
        'ORDER-BBB',
    ))->toThrow(ReservationAdoptionException::class, 'RESERVATION_CONFLICTING_OWNER');
});

// ---------------------------------------------------------------------------
// A-08: Released reservation cannot be adopted
// ---------------------------------------------------------------------------
test('A-08: adopt on released reservation throws ReservationAdoptionException', function (): void {
    $this->service->reserve(
        $this->tenant->id,
        'chk-adopt-a08',
        $this->product->id,
        null,
        Quantity::fromString('1.0000'),
        $this->context,
    );

    $this->service->release($this->tenant->id, 'chk-adopt-a08');

    expect(fn () => $this->service->adopt(
        $this->tenant->id,
        'chk-adopt-a08',
        ReservationOwnerType::ORDER,
        'ORDER-AFTER-RELEASE',
    ))->toThrow(ReservationAdoptionException::class);
});

// ---------------------------------------------------------------------------
// A-09: Committed reservation cannot be adopted
// ---------------------------------------------------------------------------
test('A-09: adopt on committed reservation throws ReservationAdoptionException', function (): void {
    $this->service->reserve(
        $this->tenant->id,
        'chk-adopt-a09',
        $this->product->id,
        null,
        Quantity::fromString('1.0000'),
        $this->context,
    );

    $this->service->commit($this->tenant->id, 'chk-adopt-a09');

    expect(fn () => $this->service->adopt(
        $this->tenant->id,
        'chk-adopt-a09',
        ReservationOwnerType::ORDER,
        'ORDER-AFTER-COMMIT',
    ))->toThrow(ReservationAdoptionException::class);
});

// ---------------------------------------------------------------------------
// A-10: Adopted reservation can be released (Order cancel flow)
// ---------------------------------------------------------------------------
test('A-10: adopted reservation can be released, releasing stock', function (): void {
    Event::fake([InventoryReservationReleased::class]);

    $this->service->reserve(
        $this->tenant->id,
        'chk-adopt-a10',
        $this->product->id,
        null,
        Quantity::fromString('2.0000'),
        $this->context,
    );

    $this->service->adopt(
        $this->tenant->id,
        'chk-adopt-a10',
        ReservationOwnerType::ORDER,
        'ORDER-UUID-010',
    );

    $released = $this->service->release($this->tenant->id, 'chk-adopt-a10');
    expect($released)->toBeTrue()
        ->and($this->stockItem->fresh()->reserved)->toBe('0.0000');

    Event::assertDispatched(InventoryReservationReleased::class);
});

// ---------------------------------------------------------------------------
// A-11: Adopted reservation can be committed (Order fulfilled flow)
// ---------------------------------------------------------------------------
test('A-11: adopted reservation can be committed, deducting on_hand', function (): void {
    $this->service->reserve(
        $this->tenant->id,
        'chk-adopt-a11',
        $this->product->id,
        null,
        Quantity::fromString('3.0000'),
        $this->context,
    );

    $this->service->adopt(
        $this->tenant->id,
        'chk-adopt-a11',
        ReservationOwnerType::ORDER,
        'ORDER-UUID-011',
    );

    $committed = $this->service->commit($this->tenant->id, 'chk-adopt-a11');
    expect($committed)->toBeTrue()
        ->and($this->stockItem->fresh()->on_hand)->toBe('7.0000');
});

// ---------------------------------------------------------------------------
// A-12: Non-existent reservation rejected
// ---------------------------------------------------------------------------
test('A-12: adopt on non-existent reservation throws ReservationAdoptionException', function (): void {
    expect(fn () => $this->service->adopt(
        $this->tenant->id,
        'key-does-not-exist',
        ReservationOwnerType::ORDER,
        'ORDER-GHOST',
    ))->toThrow(ReservationAdoptionException::class);
});

// ---------------------------------------------------------------------------
// A-13: Cross-tenant access rejected
// ---------------------------------------------------------------------------
test('A-13: adopt rejects reservation belonging to a different tenant', function (): void {
    $otherTenant = Tenant::create([
        'slug' => 'other-tenant-adopt',
        'name' => 'Other Tenant',
        'status' => 'active',
    ]);

    $this->service->reserve(
        $this->tenant->id,
        'chk-adopt-a13',
        $this->product->id,
        null,
        Quantity::fromString('1.0000'),
        $this->context,
    );

    // Attempt to adopt under the other tenant's ID (different tenant_id in the WHERE clause)
    expect(fn () => $this->service->adopt(
        $otherTenant->id,
        'chk-adopt-a13',
        ReservationOwnerType::ORDER,
        'ORDER-CROSS-TENANT',
    ))->toThrow(ReservationAdoptionException::class);
});
