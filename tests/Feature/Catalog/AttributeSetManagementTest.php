<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Models\Attribute;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'attrset-test-tenant'],
        ['name' => 'Attribute Set Tenant', 'status' => 'active']
    );

    $this->admin = User::firstOrCreate(
        ['email' => 'attrset-admin@hyperstore.test'],
        ['name' => 'Attribute Set Admin', 'password' => bcrypt('password'), 'is_super_admin' => true]
    );
});

test('api can create, retrieve and archive attribute sets with groups and contextual requiredness', function (): void {
    $attr1 = Attribute::create(['tenant_id' => $this->tenant->id, 'code' => 'size_code', 'type' => 'select']);
    $attr2 = Attribute::create(['tenant_id' => $this->tenant->id, 'code' => 'fabric', 'type' => 'text']);

    $response = $this->actingAs($this->admin)->postJson('/api/v1/catalog/attribute-sets', [
        'name' => 'Apparel Attributes',
        'code' => 'apparel-set',
        'groups' => [
            ['name' => 'General Specifications'],
            ['name' => 'Material Details'],
        ],
        'attributes' => [
            ['attribute_id' => $attr1->id, 'is_required' => true],
            ['attribute_id' => $attr2->id, 'is_required' => false],
        ],
    ], ['X-Tenant-ID' => (string) $this->tenant->id]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Apparel Attributes')
        ->assertJsonPath('data.code', 'apparel-set');

    $setId = $response->json('data.id');

    $getResponse = $this->getJson("/api/v1/catalog/attribute-sets/{$setId}", ['X-Tenant-ID' => (string) $this->tenant->id]);
    $getResponse->assertStatus(200)
        ->assertJsonCount(2, 'data.groups')
        ->assertJsonCount(2, 'data.attributes');

    $deleteResponse = $this->actingAs($this->admin)->deleteJson("/api/v1/catalog/attribute-sets/{$setId}", [], ['X-Tenant-ID' => (string) $this->tenant->id]);
    $deleteResponse->assertStatus(200);

    $this->assertDatabaseHas('attribute_sets', [
        'id' => $setId,
        'status' => 'archived',
    ]);
});
