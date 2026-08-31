<?php

declare(strict_types=1);

namespace Modules\Shipping\Calculators;

use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\RateCalculatorInterface;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingRateRule;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\ValueObjects\RateBreakdown;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;

class TableRateCalculator implements RateCalculatorInterface
{
    public function calculate(ShippingMethod $method, ShippingZone $zone, ShippingRateRequest $request): ?RateBreakdown
    {
        $currency = $request->context->currency;
        $baseRate = MoneyValue::fromMinor((int) $method->base_amount, $currency);
        $handling = MoneyValue::fromMinor((int) $method->handling_fee, $currency);
        $zero = MoneyValue::fromMinor(0, $currency);

        $rules = $method->rateRules;
        if ($rules->isEmpty()) {
            return new RateBreakdown($baseRate, $zero, $zero, $handling, $zero, $zero, $baseRate->add($handling));
        }

        // Calculate totals for condition evaluations
        $totalItems = 0;
        /** @var numeric-string $totalWeightKg */
        $totalWeightKg = '0.0000';
        $subtotalMinor = 0;

        foreach ($request->lines as $line) {
            if ($line['is_shippable'] ?? true) {
                $qty = (int) $line['quantity'];
                $totalItems += $qty;
                /** @var Weight $unitWeight */
                $unitWeight = $line['unit_weight'];
                /** @var numeric-string $uKg */
                $uKg = $unitWeight->toKg();
                /** @var numeric-string $wStep */
                $wStep = bcmul($uKg, (string) $qty, 4);
                /** @var numeric-string $totalWeightKg */
                $totalWeightKg = bcadd($totalWeightKg, $wStep, 4);
                /** @var MoneyValue $unitPrice */
                $unitPrice = $line['unit_price'];
                $subtotalMinor += $unitPrice->getMinorAmount() * $qty;
            }
        }

        $appliedRuleAmount = $zero;
        $matched = false;

        foreach ($rules as $rule) {
            /** @var ShippingRateRule $rule */
            if (! $this->evaluateConditions($rule, $totalWeightKg, $subtotalMinor, $totalItems)) {
                continue;
            }

            $matched = true;
            $ruleActionAmount = $this->calculateActionAmount($rule, $currency, $totalItems, $totalWeightKg);
            $appliedRuleAmount = $appliedRuleAmount->add($ruleActionAmount);

            if ($rule->stop_processing) {
                break;
            }
        }

        if (! $matched && $rules->isNotEmpty()) {
            return null; // No table rule matched
        }

        $finalAmount = $baseRate->add($handling)->add($appliedRuleAmount);

        return new RateBreakdown(
            baseRate: $baseRate,
            perItemAmount: $appliedRuleAmount,
            perWeightAmount: $zero,
            handlingFee: $handling,
            carrierMarkup: $zero,
            promotionDiscount: $zero,
            finalAmount: $finalAmount
        );
    }

    /**
     * @param  numeric-string  $weightKg
     */
    private function evaluateConditions(ShippingRateRule $rule, string $weightKg, int $subtotalMinor, int $totalItems): bool
    {
        $payload = $rule->conditions_payload ?? [];

        if (isset($payload['min_weight']) && is_numeric((string) $payload['min_weight']) && bccomp($weightKg, (string) $payload['min_weight'], 4) < 0) {
            return false;
        }
        if (isset($payload['max_weight']) && is_numeric((string) $payload['max_weight']) && bccomp($weightKg, (string) $payload['max_weight'], 4) > 0) {
            return false;
        }
        if (isset($payload['min_subtotal']) && $subtotalMinor < (int) $payload['min_subtotal']) {
            return false;
        }
        if (isset($payload['max_subtotal']) && $subtotalMinor > (int) $payload['max_subtotal']) {
            return false;
        }
        if (isset($payload['min_quantity']) && $totalItems < (int) $payload['min_quantity']) {
            return false;
        }
        if (isset($payload['max_quantity']) && $totalItems > (int) $payload['max_quantity']) {
            return false;
        }

        return true;
    }

    private function calculateActionAmount(ShippingRateRule $rule, string $currency, int $totalItems, string $weightKg): MoneyValue
    {
        $payload = $rule->action_payload ?? [];
        $fixed = isset($payload['amount']) ? (int) $payload['amount'] : 0;
        $perItem = isset($payload['per_item']) ? (int) $payload['per_item'] * $totalItems : 0;

        $totalMinor = $fixed + $perItem;

        return MoneyValue::fromMinor($totalMinor, $currency);
    }
}
