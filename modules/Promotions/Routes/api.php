<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Promotions\DTOs\PromotionCartItem;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Models\Coupon;
use Modules\Promotions\Models\Promotion;
use Modules\Promotions\Services\PromotionRuleEngine;

Route::prefix('api/v1/promotions')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::get('/', function (Request $request) {
        $tenantId = (int) ($request->header('X-Tenant-ID') ?? 1);

        return response()->json(['data' => Promotion::where('tenant_id', $tenantId)->with(['conditions', 'actions'])->get()]);
    });

    Route::get('coupons', function (Request $request) {
        $tenantId = (int) ($request->header('X-Tenant-ID') ?? 1);

        return response()->json(['data' => Coupon::where('tenant_id', $tenantId)->get()]);
    });

    Route::post('evaluate', function (Request $request, PromotionRuleEngine $engine) {
        $tenantId = (int) ($request->header('X-Tenant-ID') ?? 1);
        $validated = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'items' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price_minor' => ['required', 'integer', 'min:0'],
            'coupon_codes' => ['nullable', 'array'],
        ]);

        $cartItems = [];
        foreach ($validated['items'] as $it) {
            $cartItems[] = new PromotionCartItem(
                productId: (int) $it['product_id'],
                variantId: isset($it['variant_id']) ? (int) $it['variant_id'] : null,
                quantity: (int) $it['quantity'],
                unitPrice: MoneyValue::fromMinor((int) $it['unit_price_minor'], $validated['currency'])
            );
        }

        $context = new PromotionContext(
            tenantId: $tenantId,
            currency: strtoupper($validated['currency']),
            items: $cartItems,
            couponCodes: $validated['coupon_codes'] ?? []
        );

        $result = $engine->evaluate($context);

        $discountData = [];
        foreach ($result->discounts as $d) {
            $discountData[] = [
                'promotion_code' => $d->promotionCode,
                'description' => $d->description,
                'discount_minor' => $d->discountAmount->getMinorAmount(),
                'discount_formatted' => $d->discountAmount->format(),
            ];
        }

        return response()->json([
            'data' => [
                'subtotal_minor' => $result->subtotal->getMinorAmount(),
                'subtotal_formatted' => $result->subtotal->format(),
                'total_discount_minor' => $result->totalDiscount->getMinorAmount(),
                'total_discount_formatted' => $result->totalDiscount->format(),
                'final_total_minor' => $result->finalTotal->getMinorAmount(),
                'final_total_formatted' => $result->finalTotal->format(),
                'discounts' => $discountData,
            ],
        ]);
    });
});
