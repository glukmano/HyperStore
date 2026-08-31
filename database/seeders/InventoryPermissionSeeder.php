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
        'warehouses.view',
        'warehouses.manage',
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
