<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\CreateCategoryAction;
use Modules\Catalog\DTOs\CategoryData;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'cattree-tenant'],
        ['name' => 'Category Tree Tenant', 'status' => 'active']
    );

    $this->admin = User::firstOrCreate(
        ['email' => 'cat-admin@hyperstore.test'],
        ['name' => 'Cat Admin', 'password' => bcrypt('password'), 'is_super_admin' => true]
    );
});

test('api returns hierarchical category tree structure', function (): void {
    $action = app(CreateCategoryAction::class);

    $parent = $action->execute(new CategoryData(
        tenantId: $this->tenant->id,
        code: 'computers',
        translations: ['en' => ['name' => 'Computers', 'slug' => 'computers']],
    ));

    $child = $action->execute(new CategoryData(
        tenantId: $this->tenant->id,
        code: 'laptops',
        translations: ['en' => ['name' => 'Laptops', 'slug' => 'laptops']],
        parentId: $parent->id,
    ));

    $response = $this->getJson('/api/v1/catalog/categories', ['X-Tenant-ID' => (string) $this->tenant->id]);

    $response->assertStatus(200)
        ->assertJsonPath('data.0.code', 'computers')
        ->assertJsonPath('data.0.children.0.code', 'laptops');
});
