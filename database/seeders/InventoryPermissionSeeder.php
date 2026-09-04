<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class InventoryPermissionSeeder extends Seeder
{
    public const array PERMISSIONS = [
        'inventory.view',
        'inventory.manage',
        'inventory.adjust',
        'inventory.reserve',
        'inventory.transfer',
        'inventory.transfer.create',
        'inventory.transfer.dispatch',
        'inventory.transfer.receive',
        'inventory.transfer.cancel',
        'inventory.restock.manage',
        'warehouses.view',
        'warehouses.manage',
        'warehouses.vendor.manage',
        'inventory.movements.view',
        'inventory.reservations.view',
        'inventory.reconcile',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
    }
}
