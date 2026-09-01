<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ShippingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'shipping.view',
            'shipping.manage',
            'shipping.zones.view',
            'shipping.zones.manage',
            'shipping.methods.view',
            'shipping.methods.manage',
            'shipping.rates.view',
            'shipping.rates.manage',
            'shipping.rates.quote',
            'shipping.carriers.view',
            'shipping.carriers.manage',
            'shipping.credentials.manage',
            'shipping.classes.view',
            'shipping.classes.manage',
            'shipping.package_types.view',
            'shipping.package_types.manage',
            'shipping.pickup_locations.view',
            'shipping.pickup_locations.manage',
            'shipping.restrictions.view',
            'shipping.restrictions.manage',
            'shipping.mappings.view',
            'shipping.mappings.manage',
            'shipping.preview',
            'fulfillment.view',
            'fulfillment.manage',
            'fulfillment.sources.view',
            'fulfillment.sources.manage',
            'fulfillment.strategies.view',
            'fulfillment.strategies.manage',
            'fulfillment.plan',
            'fulfillment.preview',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin->givePermissionTo($permissions);

        $superAdminApi = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'sanctum']);
        $superAdminApi->givePermissionTo($permissions);
    }
}
