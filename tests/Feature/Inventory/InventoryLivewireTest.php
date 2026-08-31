<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\InventoryPermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;
use Modules\Inventory\Livewire\InventorySourceManager;
use Modules\Inventory\Livewire\WarehouseManager;
use Modules\Inventory\Models\Warehouse;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(InventoryPermissionSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'inv-lw-tenant'],
        ['name' => 'Inventory Livewire Tenant', 'status' => 'active']
    );

    $this->admin = User::firstOrCreate(
        ['email' => 'inv-admin@hyperstore.test'],
        ['name' => 'Inventory Admin', 'password' => bcrypt('password'), 'is_super_admin' => true]
    );

    $this->actingAs($this->admin);
});

test('WarehouseManager creates warehouse via Livewire', function (): void {
    Livewire::test(WarehouseManager::class)
        ->set('code', 'BASEL-WH-01')
        ->set('name', 'Basel Facility')
        ->set('country_code', 'CH')
        ->call('createWarehouse')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('warehouses', ['code' => 'BASEL-WH-01']);
});

test('InventorySourceManager creates inventory source via Livewire', function (): void {
    $wh = Warehouse::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'LUCERNE-WH',
        'name' => 'Lucerne Warehouse',
        'country_code' => 'CH',
    ]);

    Livewire::test(InventorySourceManager::class)
        ->set('code', 'LUCERNE-SRC')
        ->set('name', 'Lucerne Stock Source')
        ->set('source_type', 'warehouse')
        ->set('warehouse_id', $wh->id)
        ->call('createSource')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('inventory_sources', ['code' => 'LUCERNE-SRC']);
});
