<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class Phase17PermissionSeeder extends Seeder
{
    public const array PERMISSIONS = [
        'reviews.view',
        'reviews.moderate',
        'qa.moderate',
        'messaging.moderate',
        'cms.view',
        'cms.manage',
        'cms.page.use_html_block',
        'seo.manage',
        'search.manage',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
    }
}
