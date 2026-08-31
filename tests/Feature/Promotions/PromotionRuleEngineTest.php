<?php

declare(strict_types=1);

namespace Tests\Feature\Promotions;

use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Promotions\DTOs\PromotionCartItem;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Models\Coupon;
use Modules\Promotions\Models\Promotion;
use Modules\Promotions\Models\PromotionAction;
use Modules\Promotions\Models\PromotionCondition;
use Modules\Promotions\Services\PromotionRuleEngine;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'promo-test-tenant'],
        ['name' => 'Promo Test Tenant', 'status' => 'active']
    );
});

test('PromotionRuleEngine evaluates percentage discount with minimum cart amount condition', function (): void {
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => '10% Off on Orders Above $100',
        'code' => 'TIER-10-OFF',
        'priority' => 10,
        'status' => 'active',
    ]);

    // Condition: Min cart amount $100.00 (10000 minor)
    PromotionCondition::create([
        'promotion_id' => $promo->id,
        'condition_type' => 'min_cart_amount',
        'parameters' => ['min_amount_minor' => 10000],
    ]);

    // Action: 10% discount
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'percentage_discount',
        'parameters' => ['percentage' => 10],
    ]);

    $engine = app(PromotionRuleEngine::class);

    // 1. Cart with $50 total (5000 minor) -> Condition fails, 0 discount
    $cartBelow = [
        new PromotionCartItem(productId: 1, variantId: null, quantity: 1, unitPrice: MoneyValue::fromMinor(5000, 'USD')),
    ];
    $resBelow = $engine->evaluate(new PromotionContext(tenantId: $this->tenant->id, currency: 'USD', items: $cartBelow));
    expect($resBelow->totalDiscount->getMinorAmount())->toBe(0)
        ->and($resBelow->finalTotal->getMinorAmount())->toBe(5000);

    // 2. Cart with $150 total (15000 minor) -> 10% discount = $15.00 (1500 minor)
    $cartAbove = [
        new PromotionCartItem(productId: 1, variantId: null, quantity: 3, unitPrice: MoneyValue::fromMinor(5000, 'USD')),
    ];
    $resAbove = $engine->evaluate(new PromotionContext(tenantId: $this->tenant->id, currency: 'USD', items: $cartAbove));
    expect($resAbove->totalDiscount->getMinorAmount())->toBe(1500)
        ->and($resAbove->finalTotal->getMinorAmount())->toBe(13500);
});

test('PromotionRuleEngine evaluates Buy 2 Get 1 Free promotion correctly', function (): void {
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Buy 2 Get 1 Free on Product #10',
        'code' => 'B2G1-PROD10',
        'priority' => 10,
        'status' => 'active',
    ]);

    // Action: Buy 2 Get 1 Free on product 10
    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'buy_x_get_y',
        'parameters' => [
            'buy_quantity' => 2,
            'get_free_quantity' => 1,
            'product_id' => 10,
        ],
    ]);

    $engine = app(PromotionRuleEngine::class);

    // Buy 3 of Product #10 at $20.00 each -> Total $60.00, Discount $20.00 (1 free), Final $40.00
    $items = [
        new PromotionCartItem(productId: 10, variantId: null, quantity: 3, unitPrice: MoneyValue::fromMinor(2000, 'USD')),
    ];
    $res = $engine->evaluate(new PromotionContext(tenantId: $this->tenant->id, currency: 'USD', items: $items));

    expect($res->subtotal->getMinorAmount())->toBe(6000)
        ->and($res->totalDiscount->getMinorAmount())->toBe(2000)
        ->and($res->finalTotal->getMinorAmount())->toBe(4000);
});

test('PromotionRuleEngine validates Coupon Code with case normalization', function (): void {
    $promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Secret VIP Coupon 20%',
        'code' => 'VIP-20-COUPON',
        'priority' => 50,
        'status' => 'active',
    ]);

    Coupon::create([
        'tenant_id' => $this->tenant->id,
        'promotion_id' => $promo->id,
        'code' => 'SAVE20VIP',
        'status' => 'active',
    ]);

    PromotionCondition::create([
        'promotion_id' => $promo->id,
        'condition_type' => 'coupon',
        'parameters' => ['code' => 'SAVE20VIP'],
    ]);

    PromotionAction::create([
        'promotion_id' => $promo->id,
        'action_type' => 'percentage_discount',
        'parameters' => ['percentage' => 20],
    ]);

    $engine = app(PromotionRuleEngine::class);

    $items = [
        new PromotionCartItem(productId: 1, variantId: null, quantity: 1, unitPrice: MoneyValue::fromMinor(10000, 'USD')),
    ];

    // Entering lower case 'save20vip' should match uppercase 'SAVE20VIP'
    $context = new PromotionContext(
        tenantId: $this->tenant->id,
        currency: 'USD',
        items: $items,
        couponCodes: ['save20vip']
    );

    $res = $engine->evaluate($context);

    expect($res->totalDiscount->getMinorAmount())->toBe(2000)
        ->and($res->finalTotal->getMinorAmount())->toBe(8000);
});

test('Exclusive promotion stops evaluation of subsequent promotions', function (): void {
    // 1. High-priority Exclusive promo ($30 fixed off)
    $exclusivePromo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Exclusive $30 Voucher',
        'code' => 'EXCL-30',
        'priority' => 100,
        'is_exclusive' => true,
        'status' => 'active',
    ]);
    PromotionAction::create([
        'promotion_id' => $exclusivePromo->id,
        'action_type' => 'fixed_discount',
        'parameters' => ['amount_minor' => 3000, 'currency' => 'USD'],
    ]);

    // 2. Lower priority 10% promo (should NOT be evaluated because previous is exclusive)
    $stackablePromo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Regular 10% Discount',
        'code' => 'REG-10',
        'priority' => 10,
        'is_exclusive' => false,
        'status' => 'active',
    ]);
    PromotionAction::create([
        'promotion_id' => $stackablePromo->id,
        'action_type' => 'percentage_discount',
        'parameters' => ['percentage' => 10],
    ]);

    $engine = app(PromotionRuleEngine::class);

    $items = [
        new PromotionCartItem(productId: 1, variantId: null, quantity: 1, unitPrice: MoneyValue::fromMinor(10000, 'USD')),
    ];
    $res = $engine->evaluate(new PromotionContext(tenantId: $this->tenant->id, currency: 'USD', items: $items));

    // Only $30 discount applies (not $30 + 10%)
    expect($res->discounts)->toHaveCount(1)
        ->and($res->totalDiscount->getMinorAmount())->toBe(3000)
        ->and($res->finalTotal->getMinorAmount())->toBe(7000);
});
