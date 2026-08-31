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
        $carrier = Carrier::where('tenant_id', $method->tenant_id)->where('code', $carrierCode)->first();
        if (! $carrier || $carrier->status !== 'active') {
            return null;
        }

        $provider = $this->carrierRegistry->getProvider($carrier->provider_class ?? 'manual');
        $rates = $provider->calculateRates($carrier, $request);
        if (empty($rates)) {
            return null;
        }

        $firstRate = $rates[0];
        $currency = $request->context->currency;
        $baseRate = $firstRate->rateAmount;
        $handling = MoneyValue::fromMinor((int) $method->handling_fee, $currency);
        $zero = MoneyValue::fromMinor(0, $currency);

        $finalAmount = $baseRate->add($handling);

        return new RateBreakdown(
            baseRate: $baseRate,
            perItemAmount: $zero,
            perWeightAmount: $zero,
            handlingFee: $handling,
            carrierMarkup: $zero,
            promotionDiscount: $zero,
            finalAmount: $finalAmount
        );
    }
}
