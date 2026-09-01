<?php

declare(strict_types=1);

namespace Modules\Checkout\Services;

use Modules\Cart\Models\Cart;
use Modules\Cart\Models\CartLine;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutTotals;
use Modules\Checkout\DTOs\SelectedShippingQuote;
use Modules\Checkout\Exceptions\PriceUnavailableException;
use Modules\Checkout\Exceptions\TaxClassUnavailableException;
use Modules\Pricing\Contracts\PriceResolverInterface;
use Modules\Pricing\Contracts\TaxCalculatorInterface;
use Modules\Pricing\DTOs\PricingContext;
use Modules\Pricing\DTOs\PricingItem;
use Modules\Pricing\DTOs\TaxContext;
use Modules\Pricing\Models\TaxClass;
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

        // 1. Calculate merchandise lines using PriceResolver with exact fractional quantity support
        $merchandiseSubtotalMinor = 0;
        $linePricingBreakdown = [];
        $promoItems = [];
        $lineTaxItems = [];

        // Pre-fetch tenant default tax class in case products do not specify one explicitly
        $defaultTaxClass = TaxClass::query()
            ->where('tenant_id', $cart->tenant_id)
            ->where('is_default', true)
            ->first();

        foreach ($cart->lines as $line) {
            /** @var CartLine $line */
            $qtyStr = (string) $line->quantity;

            $pItem = new PricingItem(
                productId: $line->product_id,
                variantId: $line->variant_id,
                quantity: $qtyStr
            );

            $priceResult = $this->priceResolver->resolve($pItem, $pricingCtx);
            if ($priceResult === null) {
                throw PriceUnavailableException::forProduct($line->product_id, $line->variant_id);
            }

            $unitPrice = $priceResult->unitPrice;
            $unitPriceMinor = $unitPrice->getMinorAmount();

            // Exact multiplication with fractional quantity (e.g. 4000 * 1.25 = 5000 minor)
            $lineTotal = $unitPrice->multiply($qtyStr);
            $lineTotalMinor = $lineTotal->getMinorAmount();

            $merchandiseSubtotalMinor += $lineTotalMinor;

            $linePricingBreakdown[] = [
                'cart_line_id' => $line->id,
                'product_id' => $line->product_id,
                'variant_id' => $line->variant_id,
                'quantity' => $qtyStr,
                'unit_price_minor' => $unitPriceMinor,
                'line_total_minor' => $lineTotalMinor,
                'currency' => $currency,
            ];

            $promoItems[] = new PromotionCartItem(
                productId: $line->product_id,
                variantId: $line->variant_id,
                quantity: $qtyStr,
                unitPrice: $unitPrice,
                categoryIds: [],
                brandId: null,
                productType: (string) $line->product->product_type
            );

            // Resolve tax class: product tax_class_id -> tenant default -> throw exception (no hardcoded fallback)
            $taxClassId = $line->product->tax_class_id ?? ($defaultTaxClass !== null ? $defaultTaxClass->id : null);
            if ($taxClassId === null) {
                throw TaxClassUnavailableException::forProduct($line->product_id);
            }

            $lineTaxItems[] = [
                'line' => $line,
                'total' => $lineTotal,
                'tax_class_id' => (int) $taxClassId,
            ];
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

        // Authoritative shipping amounts come directly from SelectedShippingQuote (calculated by ShippingRateEngine)

        $shippingDiscountMinor = max(0, $shippingOriginalMinor - $shippingFinalMinor);

        $shippingOriginal = MoneyValue::fromMinor($shippingOriginalMinor, $currency);
        $shippingDiscount = MoneyValue::fromMinor($shippingDiscountMinor, $currency);
        $shippingFinal = MoneyValue::fromMinor($shippingFinalMinor, $currency);

        // 4. Line-level Typed Taxes
        $taxTotalMinor = 0;
        $taxSnapshot = [];
        $appliedTaxRates = [];

        if ($shippingAddress !== null && $merchandiseSubtotalMinor > 0) {
            $taxCtx = new TaxContext(
                tenantId: $cart->tenant_id,
                countryCode: $shippingAddress->countryCode,
                stateCode: $shippingAddress->regionCode,
                postalCode: $shippingAddress->postalCode,
                isTaxInclusive: false
            );

            // Calculate per line preserving tax class and exact line totals
            foreach ($lineTaxItems as $taxItem) {
                $lineTaxRes = $this->taxCalculator->calculate($taxItem['total'], $taxItem['tax_class_id'], $taxCtx);
                $taxTotalMinor += $lineTaxRes->taxAmount->getMinorAmount();
                foreach ($lineTaxRes->appliedRates as $r) {
                    $appliedTaxRates[] = $r;
                }
            }

            $taxSnapshot = [
                'tax_amount_minor' => $taxTotalMinor,
                'applied_rates' => $appliedTaxRates,
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
