<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Core\Tenancy\Models\Tenant;
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

    $this->tenantA = Tenant::create(['name' => 'Tenant A Pricing', 'slug' => 'tenant-a-pr', 'status' => 'active']);
    $this->tenantB = Tenant::create(['name' => 'Tenant B Pricing', 'slug' => 'tenant-b-pr', 'status' => 'active']);

    $this->productA = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenantA->id,
        productType: 'physical',
        sku: 'PROD-TENANT-A',
        translations: ['en' => ['name' => 'Tenant A Product']],
    ));

    $this->priceBookB = PriceBook::create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Tenant B Price Book',
        'code' => 'pb-tenant-b',
        'currency' => 'USD',
        'is_default' => true,
    ]);

    Price::create([
        'tenant_id' => $this->tenantB->id,
        'price_book_id' => $this->priceBookB->id,
        'product_id' => $this->productA->id,
        'amount_minor' => 5000,
        'currency' => 'USD',
    ]);
});

test('PriceResolver isolates Tenant A from Tenant B Price Books', function (): void {
    $resolver = app(PriceResolverInterface::class);

    // Request Tenant A context -> Tenant B Price Book must NOT resolve
    $contextA = new PricingContext(tenantId: $this->tenantA->id, currency: 'USD');
    $item = new PricingItem(productId: $this->productA->id, variantId: null, quantity: 1);

    $result = $resolver->resolve($item, $contextA);

    expect($result)->toBeNull();
});
