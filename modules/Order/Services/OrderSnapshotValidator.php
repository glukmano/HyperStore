<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Modules\Order\Exceptions\CheckoutReadySnapshotMissingException;

class OrderSnapshotValidator
{
    /**
     * Strictly validates the ready_snapshot from CheckoutReadyResult.
     *
     * @param  array<string, mixed>|null  $snapshot
     * @return array{
     *     context: array{store_id: int, market_id: int, channel_id: int, currency: string, locale: string},
     *     customer_data: array<string, mixed>,
     *     shipping_address: array<string, mixed>|null,
     *     billing_address: array<string, mixed>|null,
     *     totals: array{
     *         merchandise_subtotal_minor: int,
     *         line_discounts_minor: int,
     *         cart_discounts_minor: int,
     *         discount_total_minor: int,
     *         shipping_original_minor: int,
     *         shipping_discount_minor: int,
     *         shipping_total_minor: int,
     *         tax_total_minor: int,
     *         grand_total_minor: int,
     *         currency: string
     *     },
     *     lines: list<array{
     *         cart_line_id: int,
     *         product_id: int,
     *         variant_id: int|null,
     *         sku_snapshot: string|null,
     *         name_snapshot: string|null,
     *         product_type_snapshot: string|null,
     *         quantity: string,
     *         unit_price_minor: int,
     *         subtotal_minor: int,
     *         line_discount_minor: int,
     *         allocated_cart_discount_minor: int,
     *         discount_minor: int,
     *         taxable_amount_minor: int,
     *         tax_minor: int,
     *         total_minor: int,
     *         tax_class_id: int|null,
     *         tax_rate_percent: string|null,
     *         selected_options: array<string, mixed>|null,
     *         customization_metadata: array<string, mixed>|null
     *     }>,
     *     pricing_snapshot: array<string, mixed>|null,
     *     tax_snapshot: array<string, mixed>|null,
     *     promotion_snapshot: array<string, mixed>|null,
     *     fulfillment_snapshot: array<string, mixed>|null,
     *     selected_shipping_quote: array<string, mixed>|null,
     *     reservation_references: list<array{reservation_key: string, product_id: int, quantity: string}>
     * }
     *
     * @throws CheckoutReadySnapshotMissingException
     */
    public function validate(int $checkoutId, ?array $snapshot): array
    {
        if ($snapshot === null || empty($snapshot)) {
            throw CheckoutReadySnapshotMissingException::forCheckout($checkoutId);
        }

        // 1. Context validation (fail closed on missing locale or invalid values)
        $context = $snapshot['context'] ?? null;
        if (! is_array($context)) {
            throw CheckoutReadySnapshotMissingException::malformed($checkoutId, 'Missing [context] object.');
        }
        $storeId = (int) ($context['store_id'] ?? 0);
        $marketId = (int) ($context['market_id'] ?? 0);
        $channelId = (int) ($context['channel_id'] ?? 0);
        $currency = isset($context['currency']) && is_string($context['currency']) ? trim($context['currency']) : '';
        $locale = isset($context['locale']) && is_string($context['locale']) ? trim($context['locale']) : '';

        if ($storeId <= 0 || $marketId <= 0 || $channelId <= 0 || $currency === '') {
            throw CheckoutReadySnapshotMissingException::malformed($checkoutId, 'Context must contain valid store_id, market_id, channel_id, and currency.');
        }

        if ($locale === '') {
            throw CheckoutReadySnapshotMissingException::malformed($checkoutId, 'Missing or empty authoritative context [locale].');
        }

        // 2. Customer data validation
        $customerData = $snapshot['customer_data'] ?? null;
        if (! is_array($customerData) || empty($customerData['email'])) {
            throw CheckoutReadySnapshotMissingException::malformed($checkoutId, 'Customer data must contain a valid email address.');
        }

        // 3. Totals validation (Authoritative CheckoutTotals keys are REQUIRED, no silent fallback to zero)
        $totals = $snapshot['totals'] ?? null;
        if (! is_array($totals)) {
            throw CheckoutReadySnapshotMissingException::malformed($checkoutId, 'Missing [totals] object.');
        }

        $requiredTotalKeys = [
            'merchandise_subtotal',
            'line_discounts',
            'cart_discounts',
            'shipping_original',
            'shipping_discount',
            'shipping_final',
            'tax_total',
            'grand_total',
            'currency',
        ];

        foreach ($requiredTotalKeys as $key) {
            if (! array_key_exists($key, $totals)) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Totals object is missing required key [{$key}].");
            }
        }

        $subtotal = $totals['merchandise_subtotal'];
        $lineDiscounts = $totals['line_discounts'];
        $cartDiscounts = $totals['cart_discounts'];
        $shippingOriginal = $totals['shipping_original'];
        $shippingDiscount = $totals['shipping_discount'];
        $shippingFinal = $totals['shipping_final'];
        $taxTotal = $totals['tax_total'];
        $grandTotal = $totals['grand_total'];
        $totalsCurrency = trim((string) $totals['currency']);

        foreach ([
            'merchandise_subtotal' => $subtotal,
            'line_discounts' => $lineDiscounts,
            'cart_discounts' => $cartDiscounts,
            'shipping_original' => $shippingOriginal,
            'shipping_discount' => $shippingDiscount,
            'shipping_final' => $shippingFinal,
            'tax_total' => $taxTotal,
            'grand_total' => $grandTotal,
        ] as $k => $val) {
            if (! is_int($val) || $val < 0) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Totals [{$k}] must be a non-negative integer.");
            }
        }

        if ($totalsCurrency === '') {
            throw CheckoutReadySnapshotMissingException::malformed($checkoutId, 'Totals [currency] must be a non-empty string.');
        }

        // Currency Consistency Validation
        if ($totalsCurrency !== $currency) {
            throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Currency mismatch: totals [{$totalsCurrency}] !== context [{$currency}].");
        }

        $pricingSnapshot = $snapshot['pricing_snapshot'] ?? null;
        if (is_array($pricingSnapshot) && isset($pricingSnapshot['currency'])) {
            $pricingCurrency = trim((string) $pricingSnapshot['currency']);
            if ($pricingCurrency !== $currency) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Currency mismatch: pricing_snapshot [{$pricingCurrency}] !== context [{$currency}].");
            }
        }

        // Header Grand Total Full Reconciliation
        $expectedGrandTotal = $subtotal - $lineDiscounts - $cartDiscounts + $shippingFinal + $taxTotal;
        if ($grandTotal !== $expectedGrandTotal) {
            throw CheckoutReadySnapshotMissingException::malformed(
                $checkoutId,
                "Header totals reconciliation failed: Subtotal({$subtotal}) - LineDiscounts({$lineDiscounts}) - CartDiscounts({$cartDiscounts}) + ShippingFinal({$shippingFinal}) + TaxTotal({$taxTotal}) !== GrandTotal({$grandTotal}) [Expected: {$expectedGrandTotal}]."
            );
        }

        $validatedTotals = [
            'merchandise_subtotal_minor' => $subtotal,
            'line_discounts_minor' => $lineDiscounts,
            'cart_discounts_minor' => $cartDiscounts,
            'discount_total_minor' => $lineDiscounts + $cartDiscounts,
            'shipping_original_minor' => $shippingOriginal,
            'shipping_discount_minor' => $shippingDiscount,
            'shipping_total_minor' => $shippingFinal,
            'tax_total_minor' => $taxTotal,
            'grand_total_minor' => $grandTotal,
            'currency' => $totalsCurrency,
        ];

        // 4. Lines validation and exact 1:1 join with canonical pricing_snapshot.lines
        $rawLines = $snapshot['lines'] ?? null;
        if (! is_array($rawLines) || empty($rawLines)) {
            throw CheckoutReadySnapshotMissingException::malformed($checkoutId, 'Lines must be a non-empty list of items.');
        }

        $pricingLines = is_array($pricingSnapshot) && is_array($pricingSnapshot['lines'] ?? null)
            ? $pricingSnapshot['lines']
            : [];

        // Required canonical pricing line keys
        $requiredPricingKeys = [
            'cart_line_id',
            'product_id',
            'variant_id',
            'quantity',
            'unit_price_minor',
            'merchandise_line_subtotal_minor',
            'line_discount_minor',
            'allocated_cart_discount_minor',
            'taxable_amount_minor',
            'tax_minor',
            'line_total_minor',
            'currency',
            'tax_class_id',
            'tax_rate_percent',
        ];

        // Index pricing lines by cart_line_id and enforce strict canonical shape
        /** @var array<int, array<string, mixed>> $indexedPricing */
        $indexedPricing = [];
        foreach ($pricingLines as $pIdx => $pLine) {
            if (! is_array($pLine)) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Pricing line at index [{$pIdx}] is not an object.");
            }

            foreach ($requiredPricingKeys as $rKey) {
                if (! array_key_exists($rKey, $pLine)) {
                    throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Pricing line at index [{$pIdx}] is missing required key [{$rKey}].");
                }
            }

            $pCartLineId = (int) $pLine['cart_line_id'];
            if ($pCartLineId <= 0) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Pricing line at index [{$pIdx}] has invalid cart_line_id [{$pCartLineId}].");
            }
            if (isset($indexedPricing[$pCartLineId])) {
                // CASE D: duplicate pricing line identity
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Duplicate pricing line identity [{$pCartLineId}] in pricing snapshot.");
            }

            // Pricing line values type assertions
            if (! is_int($pLine['unit_price_minor']) || $pLine['unit_price_minor'] < 0) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Pricing line [{$pCartLineId}] unit_price_minor must be non-negative integer.");
            }
            if (! is_int($pLine['merchandise_line_subtotal_minor']) || $pLine['merchandise_line_subtotal_minor'] < 0) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Pricing line [{$pCartLineId}] merchandise_line_subtotal_minor must be non-negative integer.");
            }
            if (! is_int($pLine['line_discount_minor']) || $pLine['line_discount_minor'] < 0) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Pricing line [{$pCartLineId}] line_discount_minor must be non-negative integer.");
            }
            if (! is_int($pLine['allocated_cart_discount_minor']) || $pLine['allocated_cart_discount_minor'] < 0) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Pricing line [{$pCartLineId}] allocated_cart_discount_minor must be non-negative integer.");
            }
            if (! is_int($pLine['taxable_amount_minor']) || $pLine['taxable_amount_minor'] < 0) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Pricing line [{$pCartLineId}] taxable_amount_minor must be non-negative integer.");
            }
            if (! is_int($pLine['tax_minor']) || $pLine['tax_minor'] < 0) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Pricing line [{$pCartLineId}] tax_minor must be non-negative integer.");
            }
            if (! is_int($pLine['line_total_minor']) || $pLine['line_total_minor'] < 0) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Pricing line [{$pCartLineId}] line_total_minor must be non-negative integer.");
            }

            // Pricing line currency consistency
            $pLineCurr = trim((string) $pLine['currency']);
            if ($pLineCurr === '' || $pLineCurr !== $currency) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Pricing line [{$pCartLineId}] currency [{$pLineCurr}] !== context [{$currency}].");
            }

            $indexedPricing[$pCartLineId] = $pLine;
        }

        $consumedPricingIds = [];
        $validatedLines = [];

        foreach ($rawLines as $idx => $line) {
            if (! is_array($line)) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Line item at index [{$idx}] is not an object.");
            }

            $cartLineId = isset($line['cart_line_id']) ? (int) $line['cart_line_id'] : 0;
            if ($cartLineId <= 0) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Ready line item at index [{$idx}] missing valid cart_line_id.");
            }

            $productId = (int) ($line['product_id'] ?? 0);
            if ($productId <= 0) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Line item [{$cartLineId}] missing valid product_id.");
            }

            $variantId = isset($line['variant_id']) && (int) $line['variant_id'] > 0 ? (int) $line['variant_id'] : null;

            $readyQty = (string) ($line['quantity'] ?? '0');
            if (! is_numeric($readyQty) || bccomp($readyQty, '0', 8) <= 0) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Line item [{$cartLineId}] has invalid non-positive quantity [{$readyQty}].");
            }

            // CASE C: pricing line missing for a required ready line
            if (! isset($indexedPricing[$cartLineId])) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Pricing line missing for required ready line [{$cartLineId}].");
            }

            $matchedPricing = $indexedPricing[$cartLineId];
            $consumedPricingIds[$cartLineId] = true;

            // Identity verification
            $pProductId = (int) ($matchedPricing['product_id'] ?? 0);
            $pVariantId = isset($matchedPricing['variant_id']) && (int) $matchedPricing['variant_id'] > 0 ? (int) $matchedPricing['variant_id'] : null;

            if ($productId !== $pProductId || $variantId !== $pVariantId) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Identity mismatch for line [{$cartLineId}]: ready [{$productId}:{$variantId}] vs pricing [{$pProductId}:{$pVariantId}].");
            }

            // Quantity exact decimal verification
            $pricingQty = (string) ($matchedPricing['quantity'] ?? '0');
            if (! is_numeric($pricingQty) || bccomp($pricingQty, '0', 8) <= 0) {
                throw CheckoutReadySnapshotMissingException::malformed($checkoutId, "Pricing line [{$cartLineId}] has invalid non-positive quantity [{$pricingQty}].");
            }

            if (bccomp($readyQty, $pricingQty, 8) !== 0) {
                throw CheckoutReadySnapshotMissingException::malformed(
                    $checkoutId,
                    "Quantity mismatch for line [{$cartLineId}]: ready [{$readyQty}] vs pricing [{$pricingQty}]."
                );
            }

            $unitPriceMinor = $matchedPricing['unit_price_minor'];
            $subtotalMinor = $matchedPricing['merchandise_line_subtotal_minor'];
            $lineDiscountMinor = $matchedPricing['line_discount_minor'];
            $allocatedCartDiscountMinor = $matchedPricing['allocated_cart_discount_minor'];
            $taxableAmountMinor = $matchedPricing['taxable_amount_minor'];
            $taxMinor = $matchedPricing['tax_minor'];
            $totalMinor = $matchedPricing['line_total_minor'];

            $expectedTaxable = $subtotalMinor - $lineDiscountMinor - $allocatedCartDiscountMinor;
            if ($taxableAmountMinor !== $expectedTaxable) {
                throw CheckoutReadySnapshotMissingException::malformed(
                    $checkoutId,
                    "Taxable amount mismatch for line [{$cartLineId}]: taxable [{$taxableAmountMinor}] !== subtotal [{$subtotalMinor}] - line_discount [{$lineDiscountMinor}] - allocated_cart_discount [{$allocatedCartDiscountMinor}]."
                );
            }

            $expectedLineTotal = $expectedTaxable + $taxMinor;
            if ($totalMinor !== $expectedLineTotal) {
                throw CheckoutReadySnapshotMissingException::malformed(
                    $checkoutId,
                    "Line total calculation mismatch for line [{$cartLineId}]: total [{$totalMinor}] !== taxable [{$taxableAmountMinor}] + tax [{$taxMinor}]."
                );
            }

            $taxClassId = $matchedPricing['tax_class_id'] !== null ? (int) $matchedPricing['tax_class_id'] : null;
            $taxRatePercent = $matchedPricing['tax_rate_percent'] !== null ? (string) $matchedPricing['tax_rate_percent'] : null;

            $validatedLines[] = [
                'cart_line_id' => $cartLineId,
                'product_id' => $productId,
                'variant_id' => $variantId,
                'sku_snapshot' => isset($line['sku_snapshot']) ? (string) $line['sku_snapshot'] : null,
                'name_snapshot' => isset($line['name_snapshot']) ? (string) $line['name_snapshot'] : null,
                'product_type_snapshot' => isset($line['product_type_snapshot']) ? (string) $line['product_type_snapshot'] : null,
                'quantity' => $readyQty,
                'unit_price_minor' => $unitPriceMinor,
                'subtotal_minor' => $subtotalMinor,
                'line_discount_minor' => $lineDiscountMinor,
                'allocated_cart_discount_minor' => $allocatedCartDiscountMinor,
                'discount_minor' => $lineDiscountMinor + $allocatedCartDiscountMinor,
                'taxable_amount_minor' => $taxableAmountMinor,
                'tax_minor' => $taxMinor,
                'total_minor' => $totalMinor,
                'tax_class_id' => $taxClassId,
                'tax_rate_percent' => $taxRatePercent,
                'selected_options' => is_array($line['selected_options'] ?? null) ? $line['selected_options'] : (is_array($line['options'] ?? null) ? $line['options'] : null),
                'customization_metadata' => is_array($line['customization_metadata'] ?? null) ? $line['customization_metadata'] : (is_array($line['customizations'] ?? null) ? $line['customizations'] : null),
            ];
        }

        // CASE E: orphan pricing line not represented in ready lines
        $orphanIds = array_diff(array_keys($indexedPricing), array_keys($consumedPricingIds));
        if (! empty($orphanIds)) {
            throw CheckoutReadySnapshotMissingException::malformed($checkoutId, 'Orphan pricing line identity ['.implode(', ', $orphanIds).'] not represented in ready lines.');
        }

        // Commercial Reconciliation
        $sumLineSubtotals = 0;
        $sumLineDiscounts = 0;
        $sumLineAllocatedCartDiscounts = 0;
        $sumLineTaxes = 0;
        foreach ($validatedLines as $vLine) {
            $sumLineSubtotals += $vLine['subtotal_minor'];
            $sumLineDiscounts += $vLine['line_discount_minor'];
            $sumLineAllocatedCartDiscounts += $vLine['allocated_cart_discount_minor'];
            $sumLineTaxes += $vLine['tax_minor'];
        }

        if ($sumLineSubtotals !== $subtotal) {
            throw CheckoutReadySnapshotMissingException::malformed(
                $checkoutId,
                "Reconciliation failed: Sum of line subtotals [{$sumLineSubtotals}] does not match merchandise_subtotal [{$subtotal}]."
            );
        }

        if ($sumLineDiscounts !== $lineDiscounts) {
            throw CheckoutReadySnapshotMissingException::malformed(
                $checkoutId,
                "Reconciliation failed: Sum of line discounts [{$sumLineDiscounts}] does not match line_discounts [{$lineDiscounts}]."
            );
        }

        if ($sumLineAllocatedCartDiscounts !== $cartDiscounts) {
            throw CheckoutReadySnapshotMissingException::malformed(
                $checkoutId,
                "Reconciliation failed: Sum of line allocated cart discounts [{$sumLineAllocatedCartDiscounts}] does not match cart_discounts [{$cartDiscounts}]."
            );
        }

        if ($sumLineTaxes !== $taxTotal) {
            throw CheckoutReadySnapshotMissingException::malformed(
                $checkoutId,
                "Reconciliation failed: Sum of line taxes [{$sumLineTaxes}] does not match tax_total [{$taxTotal}]."
            );
        }

        // 5. Reservation references validation
        $rawRes = $snapshot['reservation_references'] ?? [];
        $validatedRes = [];
        if (is_array($rawRes)) {
            foreach ($rawRes as $r) {
                if (is_array($r) && ! empty($r['reservation_key'])) {
                    $validatedRes[] = [
                        'reservation_key' => (string) $r['reservation_key'],
                        'product_id' => (int) ($r['product_id'] ?? 0),
                        'quantity' => (string) ($r['quantity'] ?? '0'),
                    ];
                }
            }
        }

        return [
            'context' => [
                'store_id' => $storeId,
                'market_id' => $marketId,
                'channel_id' => $channelId,
                'currency' => $currency,
                'locale' => $locale,
            ],
            'customer_data' => $customerData,
            'shipping_address' => is_array($snapshot['shipping_address'] ?? null) ? $snapshot['shipping_address'] : null,
            'billing_address' => is_array($snapshot['billing_address'] ?? null) ? $snapshot['billing_address'] : null,
            'totals' => $validatedTotals,
            'lines' => $validatedLines,
            'pricing_snapshot' => is_array($snapshot['pricing_snapshot'] ?? null) ? $snapshot['pricing_snapshot'] : null,
            'tax_snapshot' => is_array($snapshot['tax_snapshot'] ?? null) ? $snapshot['tax_snapshot'] : null,
            'promotion_snapshot' => is_array($snapshot['promotion_snapshot'] ?? null) ? $snapshot['promotion_snapshot'] : null,
            'fulfillment_snapshot' => is_array($snapshot['fulfillment_snapshot'] ?? null) ? $snapshot['fulfillment_snapshot'] : null,
            'selected_shipping_quote' => is_array($snapshot['selected_shipping_quote'] ?? null) ? $snapshot['selected_shipping_quote'] : null,
            'reservation_references' => $validatedRes,
        ];
    }
}
