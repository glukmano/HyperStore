<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'brand-mgmt-tenant'],
        ['name' => 'Brand Mgmt Tenant', 'status' => 'active']
    );

    $this->admin = User::firstOrCreate(
        ['email' => 'brand-admin@hyperstore.test'],
        ['name' => 'Brand Admin', 'password' => bcrypt('password'), 'is_super_admin' => true]
    );
});

test('api can create, update, and archive brands with localized slugs', function (): void {
    $response = $this->actingAs($this->admin)->postJson('/api/v1/catalog/brands', [
        'code' => 'samsung',
        'translations' => [
            'en' => ['name' => 'Samsung', 'slug' => 'samsung', 'description' => 'Global Electronics'],
            'ar' => ['name' => 'سامسونج', 'slug' => 'samsung-ar', 'description' => 'إلكترونيات عالمية'],
        ],
        'status' => 'active',
    ], ['X-Tenant-ID' => (string) $this->tenant->id]);

    $response->assertStatus(201)
        ->assertJsonPath('data.code', 'samsung');

    $brandId = $response->json('data.id');

    // Update
    $updateResponse = $this->actingAs($this->admin)->putJson("/api/v1/catalog/brands/{$brandId}", [
        'translations' => [
            'en' => ['name' => 'Samsung Electronics', 'slug' => 'samsung-electronics'],
        ],
    ], ['X-Tenant-ID' => (string) $this->tenant->id]);

    $updateResponse->assertStatus(200);

    $this->assertDatabaseHas('brand_translations', [
        'brand_id' => $brandId,
        'locale' => 'en',
        'name' => 'Samsung Electronics',
        'slug' => 'samsung-electronics',
    ]);
});
