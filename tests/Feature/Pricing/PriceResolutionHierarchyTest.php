<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\Actions\CreateVariantAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\DTOs\VariantData;
use Modules\Catalog\Models\Attribute;
use Modules\Catalog\Models\AttributeOption;
use Modules\Pricing\Contracts\PriceResolverInterface;
use Modules\Pricing\DTOs\PricingContext;
use Modules\Pricing\DTOs\PricingItem;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TierPrice;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'pricing-test-tenant'],
        ['name' => 'Pricing Test Tenant', 'status' => 'active']
    );

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'variable',
        sku: 'HOODIE-BASE',
        translations: ['en' => ['name' => 'Premium Hoodie']],
    ));

    $colorAttr = Attribute::create(['tenant_id' => $this->tenant->id, 'code' => 'color', 'type' => 'select']);
    $redOpt = AttributeOption::create(['attribute_id' => $colorAttr->id, 'code' => 'red']);

    $this->variant = app(CreateVariantAction::class)->execute(new VariantData(
        productId: $this->product->id,
        sku: 'HOODIE-RED',
        options: [$colorAttr->id => $redOpt->id],
    ));

    // Base Price Book (Priority 0)
    $this->basePriceBook = PriceBook::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Base USD Price Book',
        'code' => 'base-usd',
        'currency' => 'USD',
        'priority' => 0,
        'is_default' => true,
        'status' => 'active',
    ]);

    // Base Product Price: $80.00 (8000 minor)
    Price::create([
        'tenant_id' => $this->tenant->id,
        'price_book_id' => $this->basePriceBook->id,
        'product_id' => $this->product->id,
        'product_variant_id' => null,
        'amount_minor' => 8000,
        'compare_at_minor' => 10000,
        'currency' => 'USD',
        'status' => 'active',
    ]);

    // Base Variant Price: $85.00 (8500 minor)
    $this->variantPrice = Price::create([
        'tenant_id' => $this->tenant->id,
        'price_book_id' => $this->basePriceBook->id,
        'product_id' => $this->product->id,
        'product_variant_id' => $this->variant->id,
        'amount_minor' => 8500,
        'currency' => 'USD',
        'status' => 'active',
    ]);
});

test('PriceResolver falls back to Product Price when Variant has no specific price', function (): void {
    $resolver = app(PriceResolverInterface::class);

    $context = new PricingContext(
        tenantId: $this->tenant->id,
        currency: 'USD'
    );

    // Request canonical product
    $item = new PricingItem(productId: $this->product->id, variantId: null, quantity: 1);
    $result = $resolver->resolve($item, $context);

    expect($result)->not->toBeNull()
        ->and($result->unitPrice->getMinorAmount())->toBe(8000)
        ->and($result->compareAtPrice?->getMinorAmount())->toBe(10000);
});

test('PriceResolver prioritizes Variant Price over Canonical Product Price', function (): void {
    $resolver = app(PriceResolverInterface::class);

    $context = new PricingContext(
        tenantId: $this->tenant->id,
        currency: 'USD'
    );

    $item = new PricingItem(productId: $this->product->id, variantId: $this->variant->id, quantity: 1);
    $result = $resolver->resolve($item, $context);

    expect($result)->not->toBeNull()
        ->and($result->unitPrice->getMinorAmount())->toBe(8500);
});

test('PriceResolver applies Quantity Tier breaks when threshold is met', function (): void {
    // Add Tier Price on Variant Price: 10+ units = $75.00 (7500 minor)
    TierPrice::create([
        'price_id' => $this->variantPrice->id,
        'min_quantity' => 10,
        'max_quantity' => null,
        'amount_minor' => 7500,
    ]);

    $resolver = app(PriceResolverInterface::class);
    $context = new PricingContext(tenantId: $this->tenant->id, currency: 'USD');

    // 1. Qty = 5 -> Standard Variant price $85.00
    $itemQty5 = new PricingItem(productId: $this->product->id, variantId: $this->variant->id, quantity: 5);
    $resQty5 = $resolver->resolve($itemQty5, $context);
    expect($resQty5->unitPrice->getMinorAmount())->toBe(8500);

    // 2. Qty = 12 -> Tier Price $75.00
    $itemQty12 = new PricingItem(productId: $this->product->id, variantId: $this->variant->id, quantity: 12);
    $resQty12 = $resolver->resolve($itemQty12, $context);
    expect($resQty12->unitPrice->getMinorAmount())->toBe(7500)
        ->and($resQty12->appliedTierMinQuantity)->toBe(10);
});

test('PriceResolver prioritizes higher-priority Customer Group Price Book', function (): void {
    $vipPriceBook = PriceBook::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'VIP Club Price Book',
        'code' => 'vip-usd',
        'currency' => 'USD',
        'customer_group_id' => 999, // VIP group
        'priority' => 10, // Higher than Base (0)
        'status' => 'active',
    ]);

    Price::create([
        'tenant_id' => $this->tenant->id,
        'price_book_id' => $vipPriceBook->id,
        'product_id' => $this->product->id,
        'product_variant_id' => null,
        'amount_minor' => 6000, // $60.00
        'currency' => 'USD',
        'status' => 'active',
    ]);

    $resolver = app(PriceResolverInterface::class);

    // Context with VIP customer group
    $vipContext = new PricingContext(
        tenantId: $this->tenant->id,
        currency: 'USD',
        customerGroupId: 999
    );

    $item = new PricingItem(productId: $this->product->id, variantId: null, quantity: 1);
    $res = $resolver->resolve($item, $vipContext);

    expect($res->unitPrice->getMinorAmount())->toBe(6000)
        ->and($res->appliedPriceBookId)->toBe($vipPriceBook->id);
});
