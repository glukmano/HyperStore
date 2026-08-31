<?php

declare(strict_types=1);

namespace Tests\Feature\Promotions;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Promotions\DTOs\PromotionCartItem;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Models\Promotion;
use Modules\Promotions\Models\PromotionAction;
use Modules\Promotions\Models\PromotionCondition;
use Modules\Promotions\Services\PromotionRuleEngine;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'adv-promo-tenant'],
        ['name' => 'Advanced Promo Tenant', 'status' => 'active']
    );
});

test('Promotion evaluates Category and Brand conditions', function (): void {
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => '20% Off Electronics Category',
        'code' => 'ELEC-20',
        'priority' => 10,
        'status' => 'active',
    ]);

    PromotionCondition::create([
        'promotion_id' => $promo->id,
        'condition_type' => 'category',
        'parameters' => ['category_ids' => [5, 10]],
    ]);

    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'percentage_discount',
        'parameters' => ['percentage' => 20],
    ]);

    $engine = app(PromotionRuleEngine::class);

    // Cart with item in Category 5 -> Applies 20% discount
    $cart = [
        new PromotionCartItem(productId: 1, variantId: null, quantity: 1, unitPrice: MoneyValue::fromMinor(10000, 'USD'), categoryIds: [5]),
    ];
    $res = $engine->evaluate(new PromotionContext(tenantId: $this->tenant->id, currency: 'USD', items: $cart));

    expect($res->totalDiscount->getMinorAmount())->toBe(2000)
        ->and($res->finalTotal->getMinorAmount())->toBe(8000);
});

test('Promotion evaluates Free Shipping Action returning benefit entitlement', function (): void {
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Free Shipping for VIPs',
        'code' => 'VIP-FREESHIP',
        'priority' => 5,
        'status' => 'active',
    ]);

    PromotionCondition::create([
        'promotion_id' => $promo->id,
        'condition_type' => 'customer_group',
        'parameters' => ['customer_group_ids' => [777]],
    ]);

    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'free_shipping',
        'parameters' => [],
    ]);

    $engine = app(PromotionRuleEngine::class);

    $cart = [
        new PromotionCartItem(productId: 1, variantId: null, quantity: 1, unitPrice: MoneyValue::fromMinor(5000, 'USD')),
    ];

    $res = $engine->evaluate(new PromotionContext(
        tenantId: $this->tenant->id,
        currency: 'USD',
        items: $cart,
        customerGroupId: 777
    ));

    expect($res->discounts)->toHaveCount(1)
        ->and($res->discounts[0]->description)->toBe('Free Standard Shipping Benefit')
        ->and($res->discounts[0]->discountAmount->getMinorAmount())->toBe(0);
});
