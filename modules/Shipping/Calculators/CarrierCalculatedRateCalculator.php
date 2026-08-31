<?php

declare(strict_types=1);

namespace Modules\Shipping\Calculators;

use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\RateCalculatorInterface;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Registries\CarrierRegistry;
use Modules\Shipping\ValueObjects\RateBreakdown;
use Modules\Shipping\ValueObjects\ShippingRateRequest;

class CarrierCalculatedRateCalculator implements RateCalculatorInterface
{
    public function __construct(private readonly CarrierRegistry $carrierRegistry) {}

    public function calculate(ShippingMethod $method, ShippingZone $zone, ShippingRateRequest $request): ?RateBreakdown
    {
        $carrierCode = $method->metadata['carrier_code'] ?? 'manual';
        $targetServiceCode = $method->metadata['service_code'] ?? null;

        $carrier = Carrier::where('tenant_id', $method->tenant_id)->where('code', $carrierCode)->first();
        if (! $carrier || $carrier->status !== 'active') {
            return null;
        }

        $providerCode = $carrier->provider_code ?? 'manual';
        $provider = $this->carrierRegistry->getProvider($providerCode);
        $rates = $provider->calculateRates($carrier, $request);
        if (empty($rates)) {
            return null;
        }

        // Explicit service matching instead of arbitrary rates[0]
        $selectedRate = null;
        if ($targetServiceCode !== null) {
            foreach ($rates as $rate) {
                if ($rate->serviceCode === $targetServiceCode) {
                    $selectedRate = $rate;
                    break;
                }
            }
        } else {
            $selectedRate = $rates[0];
        }

        if ($selectedRate === null) {
            return null;
        }

        $currency = $request->context->currency;
        $baseRate = $selectedRate->rateAmount;
        $handling = MoneyValue::fromMinor((int) $method->handling_fee, $currency);
        $zero = MoneyValue::fromMinor(0, $currency);

        // Carrier Markup calculation (markup_amount + markup_percentage)
        $markupAmountMinor = isset($method->metadata['markup_amount']) ? (int) $method->metadata['markup_amount'] : 0;
        $markupPct = isset($method->metadata['markup_percentage']) ? (float) $method->metadata['markup_percentage'] : 0.0;
        $pctAmountMinor = (int) round($baseRate->getMinorAmount() * ($markupPct / 100.0));
        $carrierMarkup = MoneyValue::fromMinor($markupAmountMinor + $pctAmountMinor, $currency);

        $finalAmount = $baseRate->add($handling)->add($carrierMarkup);

        return new RateBreakdown(
            baseRate: $baseRate,
            perItemAmount: $zero,
            perWeightAmount: $zero,
            handlingFee: $handling,
            carrierMarkup: $carrierMarkup,
            promotionDiscount: $zero,
            finalAmount: $finalAmount
        );
    }
}
