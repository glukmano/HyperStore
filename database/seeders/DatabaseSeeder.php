<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ReferenceDataSeeder::class,
            ChannelSeeder::class,
            CatalogPermissionSeeder::class,
            PricingPermissionSeeder::class,
            InventoryPermissionSeeder::class,
            PlatformAdminPermissionSeeder::class,
            MarketplacePermissionSeeder::class,
            OrderPermissionSeeder::class,
            DropshippingPermissionSeeder::class,
            PaymentPermissionSeeder::class,
        ]);

        User::firstOrCreate(
            ['email' => 'admin@hyperstore.test'],
            [
                'name' => 'Platform Super Admin',
                'password' => bcrypt('password'),
                'is_super_admin' => true,
                'status' => 'active',
                'default_locale' => 'en',
            ]
        );
    }
}
