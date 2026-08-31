<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\Warehouse;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'wh-source-tenant'],
        ['name' => 'Warehouse Source Tenant', 'status' => 'active']
    );
});

test('Warehouse and InventorySource models maintain clean decoupled relationships', function (): void {
    $warehouse = Warehouse::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'ZRH-WH-01',
        'name' => 'Zurich Central Logistics Center',
        'country_code' => 'CH',
        'city' => 'Zurich',
        'type' => 'owned',
        'status' => 'active',
    ]);

    $source = InventorySource::create([
        'tenant_id' => $this->tenant->id,
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

test('InventorySource correctly identifies stale external synchronizations', function (): void {
    $freshSource = InventorySource::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'SUP-FRESH',
        'name' => 'Supplier Fresh Feed',
        'source_type' => 'supplier',
        'last_synced_at' => now()->subMinutes(10),
        'stale_after_minutes' => 60,
    ]);
    expect($freshSource->isStale())->toBeFalse();

    $staleSource = InventorySource::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'SUP-STALE',
        'name' => 'Supplier Stale Feed',
        'source_type' => 'supplier',
        'last_synced_at' => now()->subHours(5),
        'stale_after_minutes' => 120,
    ]);
    expect($staleSource->isStale())->toBeTrue();
});
