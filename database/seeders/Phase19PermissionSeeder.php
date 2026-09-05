<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class Phase19PermissionSeeder extends Seeder
{
    public const array PERMISSIONS = [
        'affiliates.view',
        'affiliates.manage',
        'affiliate-payouts.view',
        'affiliate-payouts.manage',
        'loyalty.view',
        'loyalty.manage',
        'marketing-campaigns.view',
        'marketing-campaigns.manage',
        'customers.view',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
    }
}
