<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\CatalogPermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(CatalogPermissionSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'rbac-catalog-tenant'],
        ['name' => 'RBAC Catalog Tenant', 'status' => 'active']
    );

    $this->regularUser = User::create([
        'name' => 'Regular Staff',
        'email' => 'staff@hyperstore.test',
        'password' => bcrypt('password'),
        'is_super_admin' => false,
    ]);

    $this->privilegedUser = User::create([
        'name' => 'Catalog Manager',
        'email' => 'manager@hyperstore.test',
        'password' => bcrypt('password'),
        'is_super_admin' => false,
    ]);
    $this->privilegedUser->givePermissionTo(['products.create', 'products.update', 'products.archive', 'catalog.manage']);
});

test('user without product create permission is forbidden from creating products', function (): void {
    $response = $this->actingAs($this->regularUser)->postJson('/api/v1/catalog/products', [
        'product_type' => 'physical',
        'sku' => 'FORBIDDEN-SKU-001',
        'translations' => ['en' => ['name' => 'Forbidden Product']],
    ], ['X-Tenant-ID' => (string) $this->tenant->id]);

    $response->assertStatus(403);

    $allowedResponse = $this->actingAs($this->privilegedUser)->postJson('/api/v1/catalog/products', [
        'product_type' => 'physical',
        'sku' => 'ALLOWED-SKU-001',
        'translations' => ['en' => ['name' => 'Allowed Product']],
    ], ['X-Tenant-ID' => (string) $this->tenant->id]);

    $allowedResponse->assertStatus(201);
});
