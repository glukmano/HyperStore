<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class Phase22PermissionSeeder extends Seeder
{
    public const array PERMISSIONS = [
        'pos.registers.view',
        'pos.registers.manage',
        'pos.register.open',
        'pos.session.close',
        'pos.sale.create',
        'pos.discount.manual',
        'pos.refund.process',
        'pos.void',
        'pos.cash.movement',
        'pos.manager.override',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
    }
}
