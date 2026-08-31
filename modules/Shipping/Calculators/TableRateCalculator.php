<?php

declare(strict_types=1);

namespace Modules\Shipping\Calculators;

use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\RateCalculatorInterface;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingRateRule;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\TableRate\TableRateActionRegistry;
use Modules\Shipping\TableRate\TableRateConditionRegistry;
use Modules\Shipping\ValueObjects\RateBreakdown;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;

class TableRateCalculator implements RateCalculatorInterface
{
    public function __construct(
        private readonly ?TableRateConditionRegistry $conditionRegistry = null,
        private readonly ?TableRateActionRegistry $actionRegistry = null
    ) {}

    public function calculate(ShippingMethod $method, ShippingZone $zone, ShippingRateRequest $request): ?RateBreakdown
    {
        $currency = $method->currency ?? $request->context->currency;
        $baseRate = MoneyValue::fromMinor((int) $method->base_amount, $currency);
        $handling = MoneyValue::fromMinor((int) $method->handling_fee, $currency);
        $zero = MoneyValue::fromMinor(0, $currency);

        $rules = $method->rateRules;
        if ($rules->isEmpty()) {
            return new RateBreakdown($baseRate, $zero, $zero, $handling, $zero, $zero, $baseRate->add($handling));
        }

        $condRegistry = $this->conditionRegistry ?? new TableRateConditionRegistry;
        $actRegistry = $this->actionRegistry ?? new TableRateActionRegistry;

        // Calculate totals
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

        $evalContext = [
            'total_items' => $totalItems,
            'total_weight_kg' => $totalWeightKg,
            'subtotal_minor' => $subtotalMinor,
            'package_count' => 1,
            'request' => $request,
        ];

        $appliedRuleAmount = $zero;
        $matched = false;

        foreach ($rules as $rule) {
            /** @var ShippingRateRule $rule */
            if (! $condRegistry->evaluate($rule, $evalContext)) {
                continue;
            }

            $matched = true;
            $ruleActionAmount = $actRegistry->calculate($rule, $currency, $evalContext);
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
}
