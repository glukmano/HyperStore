<?php

declare(strict_types=1);

namespace Modules\Shipping\Providers;

use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\CarrierProviderInterface;
use Modules\Shipping\Models\Carrier;
use Modules\Shipping\Models\CarrierService;
use Modules\Shipping\ValueObjects\CarrierRateResult;
use Modules\Shipping\ValueObjects\ShippingRateRequest;

class ManualCarrierProvider implements CarrierProviderInterface
{
    public function calculateRates(Carrier $carrier, ShippingRateRequest $request): array
    {
        $currency = $request->context->currency;
        $results = [];

        foreach ($carrier->services as $service) {
            /** @var CarrierService $service */
            if ($service->status !== 'active') {
                continue;
            }

            $rateMinor = $service->markup_amount > 0 ? (int) $service->markup_amount : 1000;
            $results[] = new CarrierRateResult(
                carrierCode: $carrier->code,
                serviceCode: $service->code,
                serviceName: $service->name,
                rateAmount: MoneyValue::fromMinor($rateMinor, $currency),
                transitDaysMin: (int) $service->transit_days_min,
                transitDaysMax: (int) $service->transit_days_max,
                metadata: ['provider' => 'manual']
            );
        }

        return $results;
    }
}
