<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PlatformAdminPermissionSeeder extends Seeder
{
    public const array PERMISSIONS = [
        'stores.view',
        'stores.manage',
        'markets.view',
        'markets.manage',
        'channels.view',
        'channels.manage',
        'settings.manage',
        'users.view',
        'users.manage',
        'roles.manage',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
    }
}
