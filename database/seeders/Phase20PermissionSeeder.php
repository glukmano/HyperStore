<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class Phase20PermissionSeeder extends Seeder
{
    public const array PERMISSIONS = [
        'b2b.companies.view',
        'b2b.companies.manage',
        'b2b.quotes.view',
        'b2b.quotes.manage',
        'auctions.view',
        'auctions.manage',
        'booking.view',
        'booking.manage',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
    }
}
