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
use Modules\Promotions\Services\CouponValidationService;

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::firstOrCreate(
        ['slug' => 'coupon-limit-tenant'],
        ['name' => 'Coupon Limit Tenant', 'status' => 'active']
    );

    $this->promo = Promotion::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'One-Time Per Customer Coupon',
        'code' => 'ONETIME-PER-CUST',
        'status' => 'active',
    ]);
});

test('CouponValidationService enforces per-customer limit correctly', function (): void {
    $coupon = Coupon::create([
        'tenant_id' => $this->tenant->id,
        'promotion_id' => $this->promo->id,
        'code' => 'ONCEONLY',
        'per_customer_limit' => 1,
        'status' => 'active',
    ]);

    $service = app(CouponValidationService::class);
    $cart = [new PromotionCartItem(productId: 1, variantId: null, quantity: 1, unitPrice: MoneyValue::fromMinor(1000, 'USD'))];

    // Customer #42 initial evaluation -> VALID
    $context = new PromotionContext(tenantId: $this->tenant->id, currency: 'USD', items: $cart, customerId: 42);
    $validated = $service->validate('onceonly', $context);
    expect($validated)->not->toBeNull()
        ->and($validated->code)->toBe('ONCEONLY');

    // Record usage for Customer #42
    $service->recordUsage($coupon, customerId: 42, customerEmail: 'cust42@hyperstore.test');

    // Customer #42 second evaluation -> EXCEEDED LIMIT (Returns null)
    $secondTry = $service->validate('onceonly', $context);
    expect($secondTry)->toBeNull();

    // Customer #99 (different customer) -> Still VALID
    $contextOther = new PromotionContext(tenantId: $this->tenant->id, currency: 'USD', items: $cart, customerId: 99);
    $otherTry = $service->validate('onceonly', $contextOther);
    expect($otherTry)->not->toBeNull();
});
