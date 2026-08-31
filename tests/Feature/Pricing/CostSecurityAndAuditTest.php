<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PricingPermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Pricing\Contracts\PriceResolverInterface;
use Modules\Pricing\DTOs\PricingContext;
use Modules\Pricing\DTOs\PricingItem;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(PricingPermissionSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'cost-sec-tenant'],
        ['name' => 'Cost Security Tenant', 'status' => 'active']
    );

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'CONFIDENTIAL-COST-SKU',
        translations: ['en' => ['name' => 'Confidential Product']],
    ));

    $this->priceBook = PriceBook::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Default USD',
        'code' => 'def-cost-usd',
        'currency' => 'USD',
        'is_default' => true,
    ]);

    // Price: Selling $100.00 (10000 minor), Cost $40.00 (4000 minor)
    Price::create([
        'tenant_id' => $this->tenant->id,
        'price_book_id' => $this->priceBook->id,
        'product_id' => $this->product->id,
        'amount_minor' => 10000,
        'cost_minor' => 4000,
        'currency' => 'USD',
    ]);
});

test('PriceResolver returns cost data in PriceResult for authorized backend services', function (): void {
    $resolver = app(PriceResolverInterface::class);
    $context = new PricingContext(tenantId: $this->tenant->id, currency: 'USD');
    $item = new PricingItem(productId: $this->product->id, variantId: null, quantity: 1);

    $result = $resolver->resolve($item, $context);

    expect($result)->not->toBeNull()
        ->and($result->unitPrice->getMinorAmount())->toBe(10000)
        ->and($result->costPrice?->getMinorAmount())->toBe(4000);
});

test('Public pricing API endpoint strictly conceals cost figures', function (): void {
    $user = User::create([
        'name' => 'Store Front Customer',
        'email' => 'customer@hyperstore.test',
        'password' => bcrypt('password'),
        'is_super_admin' => false,
    ]);

    $response = $this->actingAs($user)->postJson('/api/v1/pricing/resolve', [
        'product_id' => $this->product->id,
        'currency' => 'USD',
    ], ['X-Tenant-ID' => (string) $this->tenant->id]);

    $response->assertStatus(200)
        ->assertJsonPath('data.unit_price_minor', 10000)
        ->assertJsonMissing(['cost_minor' => 4000])
        ->assertJsonMissingPath('data.cost_minor');
});
