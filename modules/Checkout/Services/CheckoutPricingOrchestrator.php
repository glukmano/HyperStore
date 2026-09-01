<?php

declare(strict_types=1);

namespace Modules\Checkout\Services;

use Modules\Cart\Models\Cart;
use Modules\Cart\Models\CartLine;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutTotals;
use Modules\Checkout\DTOs\SelectedShippingQuote;
use Modules\Pricing\Contracts\PriceResolverInterface;
use Modules\Pricing\Contracts\TaxCalculatorInterface;
use Modules\Pricing\DTOs\PricingContext;
use Modules\Pricing\DTOs\PricingItem;
use Modules\Pricing\DTOs\TaxContext;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Promotions\DTOs\PromotionCartItem;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Services\PromotionRuleEngine;

class CheckoutPricingOrchestrator
{
    public function __construct(
        private readonly PriceResolverInterface $priceResolver,
        private readonly PromotionRuleEngine $promotionRuleEngine,
        private readonly TaxCalculatorInterface $taxCalculator
    ) {}

    /**
     * Re-calculates pricing, promotions, taxes, and totals deterministically.
     *
     * @return array{
     *     totals: CheckoutTotals,
     *     pricing_snapshot: array<string, mixed>,
     *     promotion_snapshot: array<string, mixed>,
     *     tax_snapshot: array<string, mixed>
     * }
     */
    public function calculate(
        Cart $cart,
        ?CheckoutAddress $shippingAddress = null,
        ?SelectedShippingQuote $selectedShippingQuote = null
    ): array {
        $cart->loadMissing('lines.product');
        $currency = $cart->currency;

        $pricingCtx = new PricingContext(
            tenantId: $cart->tenant_id,
            currency: $currency,
            storeId: $cart->store_id,
            marketId: $cart->market_id,
            channelId: $cart->channel_id,
            customerGroupId: null
        );

        // 1. Calculate merchandise lines using PriceResolver
        $merchandiseSubtotalMinor = 0;
        $linePricingBreakdown = [];
        $promoItems = [];

        foreach ($cart->lines as $line) {
            /** @var CartLine $line */
            $qty = $line->getQuantityVO()->toInt();

            $pItem = new PricingItem(
                productId: $line->product_id,
                variantId: $line->variant_id,
                quantity: $qty
            );

            $priceResult = $this->priceResolver->resolve($pItem, $pricingCtx);
            $unitPrice = $priceResult !== null ? $priceResult->unitPrice : MoneyValue::fromMinor(1000, $currency);
            $unitPriceMinor = $unitPrice->getMinorAmount();
            $lineTotal = $unitPrice->multiply($qty);
            $lineTotalMinor = $lineTotal->getMinorAmount();

            $merchandiseSubtotalMinor += $lineTotalMinor;

            $linePricingBreakdown[] = [
                'cart_line_id' => $line->id,
                'product_id' => $line->product_id,
                'variant_id' => $line->variant_id,
                'quantity' => $line->quantity,
                'unit_price_minor' => $unitPriceMinor,
                'line_total_minor' => $lineTotalMinor,
                'currency' => $currency,
            ];

            $promoItems[] = new PromotionCartItem(
                productId: $line->product_id,
                variantId: $line->variant_id,
                quantity: $qty,
                unitPrice: $unitPrice,
                categoryIds: [],
                brandId: null,
                productType: (string) $line->product->product_type
            );
        }

        $merchandiseSubtotal = MoneyValue::fromMinor($merchandiseSubtotalMinor, $currency);

        // 2. Evaluate Promotions & Coupons
        $promoContext = new PromotionContext(
            tenantId: $cart->tenant_id,
            currency: $currency,
            items: $promoItems,
            storeId: $cart->store_id,
            marketId: $cart->market_id,
            channelId: $cart->channel_id,
            customerGroupId: null,
            customerId: $cart->user_id,
            couponCodes: $cart->coupon_code !== null ? [$cart->coupon_code] : []
        );

        $promoResult = $this->promotionRuleEngine->evaluate($promoContext);

        $totalDiscountMinor = $promoResult->totalDiscount->getMinorAmount();
        // Prevent negative merchandise total
        $maxAllowedDiscount = $merchandiseSubtotalMinor;
        if ($totalDiscountMinor > $maxAllowedDiscount) {
            $totalDiscountMinor = $maxAllowedDiscount;
        }

        $lineDiscounts = MoneyValue::zero($currency);
        $cartDiscounts = MoneyValue::fromMinor($totalDiscountMinor, $currency);

        // 3. Shipping amount
        $shippingOriginalMinor = $selectedShippingQuote !== null ? $selectedShippingQuote->originalAmount->getMinorAmount() : 0;
        $shippingFinalMinor = $selectedShippingQuote !== null ? $selectedShippingQuote->finalAmount->getMinorAmount() : 0;

        // Apply FreeShipping promotion benefit if active in entitlements
        if (isset($promoResult->entitlements['free_shipping']) && $promoResult->entitlements['free_shipping'] === true) {
            $shippingFinalMinor = 0;
        }

        $shippingDiscountMinor = max(0, $shippingOriginalMinor - $shippingFinalMinor);

        $shippingOriginal = MoneyValue::fromMinor($shippingOriginalMinor, $currency);
        $shippingDiscount = MoneyValue::fromMinor($shippingDiscountMinor, $currency);
        $shippingFinal = MoneyValue::fromMinor($shippingFinalMinor, $currency);

        // 4. Taxes
        $taxTotalMinor = 0;
        $taxSnapshot = [];

        if ($shippingAddress !== null && $merchandiseSubtotalMinor > 0) {
            $taxCtx = new TaxContext(
                tenantId: $cart->tenant_id,
                countryCode: $shippingAddress->countryCode,
                stateCode: $shippingAddress->regionCode,
                postalCode: $shippingAddress->postalCode,
                isTaxInclusive: false
            );

            // Standard taxable subtotal calculation
            $taxableSubtotal = MoneyValue::fromMinor(max(0, $merchandiseSubtotalMinor - $totalDiscountMinor), $currency);
            $taxRes = $this->taxCalculator->calculate($taxableSubtotal, 1, $taxCtx);
            $taxTotalMinor = $taxRes->taxAmount->getMinorAmount();
            $taxSnapshot = [
                'net_amount_minor' => $taxRes->netAmount->getMinorAmount(),
                'tax_amount_minor' => $taxRes->taxAmount->getMinorAmount(),
                'gross_amount_minor' => $taxRes->grossAmount->getMinorAmount(),
                'applied_rates' => $taxRes->appliedRates,
            ];
        }

        $taxTotal = MoneyValue::fromMinor($taxTotalMinor, $currency);

        // 5. Grand Total Reconciliation
        $grandTotalMinor = $merchandiseSubtotalMinor
            - $lineDiscounts->getMinorAmount()
            - $cartDiscounts->getMinorAmount()
            + $shippingFinalMinor
            + $taxTotalMinor;

        $grandTotal = MoneyValue::fromMinor(max(0, $grandTotalMinor), $currency);

        $totals = new CheckoutTotals(
            merchandiseSubtotal: $merchandiseSubtotal,
            lineDiscounts: $lineDiscounts,
            cartDiscounts: $cartDiscounts,
            shippingOriginal: $shippingOriginal,
            shippingDiscount: $shippingDiscount,
            shippingFinal: $shippingFinal,
            taxTotal: $taxTotal,
            grandTotal: $grandTotal
        );

        $discountsData = [];
        foreach ($promoResult->discounts as $d) {
            $discountsData[] = [
                'promotion_id' => $d->promotionId,
                'promotion_code' => $d->promotionCode,
                'discount_amount_minor' => $d->discountAmount->getMinorAmount(),
                'coupon_code' => $d->couponCode,
            ];
        }

        return [
            'totals' => $totals,
            'pricing_snapshot' => [
                'lines' => $linePricingBreakdown,
                'subtotal_minor' => $merchandiseSubtotalMinor,
                'currency' => $currency,
                'calculated_at' => now()->toIso8601String(),
            ],
            'promotion_snapshot' => [
                'total_discount_minor' => $totalDiscountMinor,
                'discounts' => $discountsData,
                'entitlements' => $promoResult->entitlements,
            ],
            'tax_snapshot' => $taxSnapshot,
        ];
    }
}
