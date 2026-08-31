<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class CatalogPermissionSeeder extends Seeder
{
    public const array PERMISSIONS = [
        'catalog.view',
        'catalog.manage',
        'products.view',
        'products.create',
        'products.update',
        'products.archive',
        'categories.view',
        'categories.manage',
        'brands.view',
        'brands.manage',
        'attributes.view',
        'attributes.manage',
        'attribute_sets.view',
        'attribute_sets.manage',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
    }
}
