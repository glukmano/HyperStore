<?php

declare(strict_types=1);

namespace Modules\Shipping\Calculators;

use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\RateCalculatorInterface;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\ValueObjects\RateBreakdown;
use Modules\Shipping\ValueObjects\ShippingRateRequest;

class FlatRateCalculator implements RateCalculatorInterface
{
    public function calculate(ShippingMethod $method, ShippingZone $zone, ShippingRateRequest $request): ?RateBreakdown
    {
        $currency = $method->currency ?? $request->context->currency;
        $baseRate = MoneyValue::fromMinor((int) $method->base_amount, $currency);
        $handling = MoneyValue::fromMinor((int) $method->handling_fee, $currency);
        $zero = MoneyValue::fromMinor(0, $currency);

        $totalItems = 0;
        foreach ($request->lines as $line) {
            if ($line['is_shippable'] ?? true) {
                $totalItems += (int) $line['quantity'];
            }
        }

        $perItemAmount = $zero;
        if (isset($method->metadata['per_item_fee']) && is_numeric($method->metadata['per_item_fee'])) {
            $perItemUnit = MoneyValue::fromMinor((int) $method->metadata['per_item_fee'], $currency);
            $perItemAmount = $perItemUnit->multiply($totalItems);
        }

        $finalAmount = $baseRate->add($handling)->add($perItemAmount);

        return new RateBreakdown(
            baseRate: $baseRate,
            perItemAmount: $perItemAmount,
            perWeightAmount: $zero,
            handlingFee: $handling,
            carrierMarkup: $zero,
            promotionDiscount: $zero,
            finalAmount: $finalAmount
        );
    }
}
