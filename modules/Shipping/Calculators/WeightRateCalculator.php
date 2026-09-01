<?php

declare(strict_types=1);

namespace Modules\Shipping\Calculators;

use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\RateCalculatorInterface;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\ValueObjects\RateBreakdown;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;

class WeightRateCalculator implements RateCalculatorInterface
{
    public function calculate(ShippingMethod $method, ShippingZone $zone, ShippingRateRequest $request): ?RateBreakdown
    {
        $currency = $method->currency ?? $request->context->currency;
        $baseRate = MoneyValue::fromMinor((int) $method->base_amount, $currency);
        $handling = MoneyValue::fromMinor((int) $method->handling_fee, $currency);
        $zero = MoneyValue::fromMinor(0, $currency);

        /** @var numeric-string $totalWeightKg */
        $totalWeightKg = '0.0000';
        foreach ($request->lines as $line) {
            if ($line['is_shippable'] ?? true) {
                /** @var Weight $unitWeight */
                $unitWeight = $line['unit_weight'];
                /** @var numeric-string $uKg */
                $uKg = $unitWeight->toKg();
                /** @var numeric-string $lineKg */
                $lineKg = bcmul($uKg, (string) $line['quantity'], 4);
                /** @var numeric-string $totalWeightKg */
                $totalWeightKg = bcadd($totalWeightKg, $lineKg, 4);
            }
        }

        // Validate method weight limits
        if ($method->min_weight !== null && bccomp($totalWeightKg, (string) $method->min_weight, 4) < 0) {
            return null;
        }
        if ($method->max_weight !== null && bccomp($totalWeightKg, (string) $method->max_weight, 4) > 0) {
            return null;
        }

        $perWeightAmount = $zero;
        if (isset($method->metadata['per_kg_fee']) && is_numeric($method->metadata['per_kg_fee'])) {
            $perKgMinor = (int) $method->metadata['per_kg_fee'];
            $parts = explode('.', $totalWeightKg);
            $intPart = (int) $parts[0];
            $frac = isset($parts[1]) && is_numeric($parts[1]) ? (string) $parts[1] : '0';
            $hasFraction = bccomp($frac, '0', 4) > 0;
            $totalKgRounded = $intPart + ($hasFraction ? 1 : 0);
            $perWeightAmount = MoneyValue::fromMinor($perKgMinor * $totalKgRounded, $currency);
        }

        $finalAmount = $baseRate->add($handling)->add($perWeightAmount);

        return new RateBreakdown(
            baseRate: $baseRate,
            perItemAmount: $zero,
            perWeightAmount: $perWeightAmount,
            handlingFee: $handling,
            carrierMarkup: $zero,
            promotionDiscount: $zero,
            finalAmount: $finalAmount
        );
    }
}
