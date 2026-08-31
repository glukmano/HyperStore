<?php

declare(strict_types=1);

namespace Modules\Shipping\Calculators;

use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\RateCalculatorInterface;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\ValueObjects\RateBreakdown;
use Modules\Shipping\ValueObjects\ShippingRateRequest;

class FreeShippingCalculator implements RateCalculatorInterface
{
    public function calculate(ShippingMethod $method, ShippingZone $zone, ShippingRateRequest $request): ?RateBreakdown
    {
        $currency = $request->context->currency;
        $zero = MoneyValue::fromMinor(0, $currency);

        // Check configured min_subtotal threshold if specified
        if ($method->min_subtotal !== null) {
            $subtotalMinor = 0;
            foreach ($request->lines as $line) {
                if ($line['is_shippable'] ?? true) {
                    /** @var MoneyValue $unitPrice */
                    $unitPrice = $line['unit_price'];
                    $subtotalMinor += $unitPrice->getMinorAmount() * (int) $line['quantity'];
                }
            }

            if ($subtotalMinor < (int) $method->min_subtotal) {
                return null; // Minimum subtotal threshold not reached
            }
        }

        return new RateBreakdown(
            baseRate: $zero,
            perItemAmount: $zero,
            perWeightAmount: $zero,
            handlingFee: $zero,
            carrierMarkup: $zero,
            promotionDiscount: $zero,
            finalAmount: $zero
        );
    }
}
