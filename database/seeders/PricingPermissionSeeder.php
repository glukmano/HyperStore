<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PricingPermissionSeeder extends Seeder
{
    public const array PERMISSIONS = [
        'pricing.view',
        'pricing.manage',
        'pricing.cost.view',
        'tax.view',
        'tax.manage',
        'promotions.view',
        'promotions.manage',
        'coupons.view',
        'coupons.manage',
        'exchange_rates.view',
        'exchange_rates.manage',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
    }
}
