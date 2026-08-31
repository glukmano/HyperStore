<?php

declare(strict_types=1);

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;

beforeEach(function (): void {
    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'api-test-tenant'],
        ['name' => 'API Test Tenant', 'status' => 'active']
    );

    $this->admin = User::firstOrCreate(
        ['email' => 'catalog-admin@hyperstore.test'],
        ['name' => 'Catalog Admin', 'password' => bcrypt('password'), 'is_super_admin' => true]
    );
});

test('api returns list of product types and capabilities', function (): void {
    $response = $this->getJson('/api/v1/catalog/product-types');

    $response->assertStatus(200)
        ->assertJsonStructure(['data' => [['id', 'name', 'description', 'capabilities']]]);
});

test('api creates, retrieves, updates and archives product', function (): void {
    $headers = ['X-Tenant-ID' => (string) $this->tenant->id];

    // 1. Create Product
    $createResponse = $this->actingAs($this->admin)->postJson('/api/v1/catalog/products', [
        'product_type' => 'physical',
        'sku' => 'API-PROD-999',
        'translations' => [
            'en' => ['name' => 'API Product Nine', 'short_description' => 'Short desc'],
        ],
        'status' => 'active',
    ], $headers);

    $createResponse->assertStatus(201);
    $productId = $createResponse->json('data.id');

    // 2. Get Product
    $getResponse = $this->getJson("/api/v1/catalog/products/{$productId}", $headers);
    $getResponse->assertStatus(200)
        ->assertJsonPath('data.sku', 'API-PROD-999');

    // 3. Update Product
    $updateResponse = $this->actingAs($this->admin)->putJson("/api/v1/catalog/products/{$productId}", [
        'sku' => 'API-PROD-999-UPDATED',
    ], $headers);

    $updateResponse->assertStatus(200)
        ->assertJsonPath('data.sku', 'API-PROD-999-UPDATED');

    // 4. Archive Product (DELETE route)
    $deleteResponse = $this->actingAs($this->admin)->deleteJson("/api/v1/catalog/products/{$productId}", [], $headers);
    $deleteResponse->assertStatus(200)
        ->assertJsonPath('data.status', 'archived');
});
