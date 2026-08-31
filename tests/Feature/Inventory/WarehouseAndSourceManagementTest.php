<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use InvalidArgumentException;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\Warehouse;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenantA = Tenant::create(['slug' => 'wh-tenant-a', 'name' => 'Tenant A', 'status' => 'active']);
    $this->tenantB = Tenant::create(['slug' => 'wh-tenant-b', 'name' => 'Tenant B', 'status' => 'active']);
});

test('Warehouse and InventorySource models maintain clean decoupled relationships', function (): void {
    $warehouse = Warehouse::create([
        'tenant_id' => $this->tenantA->id,
        'code' => 'ZRH-WH-01',
        'name' => 'Zurich Central Logistics Center',
        'country_code' => 'CH',
        'city' => 'Zurich',
        'type' => 'fulfillment_center',
        'status' => 'active',
    ]);

    $source = InventorySource::create([
        'tenant_id' => $this->tenantA->id,
        'warehouse_id' => $warehouse->id,
        'code' => 'ZRH-SRC-01',
        'name' => 'Zurich Facility Stock Source',
        'source_type' => 'warehouse',
        'priority' => 10,
        'status' => 'active',
    ]);

    expect($source->warehouse->id)->toBe($warehouse->id)
        ->and($warehouse->inventorySources)->toHaveCount(1);
});

test('InventorySource rejects linking to a Warehouse of a different tenant', function (): void {
    $whB = Warehouse::create([
        'tenant_id' => $this->tenantB->id,
        'code' => 'WH-B-FOREIGN',
        'name' => 'Foreign Wh',
        'country_code' => 'CH',
    ]);

    expect(fn () => InventorySource::create([
        'tenant_id' => $this->tenantA->id,
        'warehouse_id' => $whB->id,
        'code' => 'SRC-A-ILLEGAL',
        'name' => 'Illegal Cross-Tenant Source',
        'source_type' => 'warehouse',
    ]))->toThrow(InvalidArgumentException::class);
});

test('InventorySource correctly identifies stale external synchronizations', function (): void {
    $freshSource = InventorySource::create([
        'tenant_id' => $this->tenantA->id,
        'code' => 'SUP-FRESH',
        'name' => 'Supplier Fresh Feed',
        'source_type' => 'supplier',
        'last_synced_at' => now()->subMinutes(10),
        'stale_after_minutes' => 60,
    ]);
    expect($freshSource->isStale())->toBeFalse();

    $staleSource = InventorySource::create([
        'tenant_id' => $this->tenantA->id,
        'code' => 'SUP-STALE',
        'name' => 'Supplier Stale Feed',
        'source_type' => 'supplier',
        'last_synced_at' => now()->subHours(5),
        'stale_after_minutes' => 120,
    ]);
    expect($staleSource->isStale())->toBeTrue();
});
