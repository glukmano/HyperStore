<?php

declare(strict_types=1);

namespace Modules\Pricing\Services;

use Modules\Pricing\Contracts\TaxCalculatorInterface;
use Modules\Pricing\DTOs\TaxContext;
use Modules\Pricing\DTOs\TaxResult;
use Modules\Pricing\Models\TaxRate;
use Modules\Pricing\Models\TaxZone;
use Modules\Pricing\ValueObjects\MoneyValue;

class TaxCalculator implements TaxCalculatorInterface
{
    public function calculate(MoneyValue $amount, int $taxClassId, TaxContext $context): TaxResult
    {
        $currency = $amount->getCurrencyCode();

        // 1. Find matching tax zones
        $zoneQuery = TaxZone::query()
            ->where('tenant_id', $context->tenantId);

        if ($context->countryCode !== null) {
            $zoneQuery->where(fn ($q) => $q->where('country_code', $context->countryCode)->orWhereNull('country_code'));
        }

        $matchingZones = $zoneQuery->orderByDesc('priority')->pluck('id')->all();

        // 2. Find matching rates
        $rates = TaxRate::query()
            ->where('tenant_id', $context->tenantId)
            ->where('tax_class_id', $taxClassId)
            ->whereIn('tax_zone_id', $matchingZones)
            ->orderByDesc('priority')
            ->get();

        if ($rates->isEmpty()) {
            return new TaxResult(
                netAmount: $amount,
                taxAmount: MoneyValue::zero($currency),
                grossAmount: $amount,
                appliedRates: []
            );
        }

        $totalRatePercent = '0';
        $appliedRateDetails = [];

        foreach ($rates as $r) {
            /** @var numeric-string $rateP */
            $rateP = (string) $r->rate_percentage;
            $totalRatePercent = bcadd($totalRatePercent, $rateP, 4);
            $appliedRateDetails[] = [
                'rate_name' => $r->name,
                'percentage' => (string) $r->rate_percentage,
                'amount_minor' => 0,
            ];
        }

        if ($context->isTaxInclusive) {
            // Price entered includes tax: gross = amount
            $gross = $amount;
            // net = gross / (1 + rate/100)
            $divisor = bcadd('1', bcdiv($totalRatePercent, '100', 6), 6);
            $netDec = bcdiv($gross->getDecimalAmount(), $divisor, 6);
            $net = MoneyValue::fromDecimal($netDec, $currency);
            $tax = $gross->subtract($net);
        } else {
            // Price entered excludes tax: net = amount
            $net = $amount;
            /** @var numeric-string $netDec */
            $netDec = (string) $net->getDecimalAmount();
            $taxDec = bcmul($netDec, bcdiv($totalRatePercent, '100', 6), 6);
            $tax = MoneyValue::fromDecimal($taxDec, $currency);
            $gross = $net->add($tax);
        }

        return new TaxResult(
            netAmount: $net,
            taxAmount: $tax,
            grossAmount: $gross,
            appliedRates: $appliedRateDetails
        );
    }
}
