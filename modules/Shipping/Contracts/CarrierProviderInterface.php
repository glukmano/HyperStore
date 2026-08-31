<?php

declare(strict_types=1);

namespace Modules\Shipping\Contracts;

use Modules\Shipping\Models\Carrier;
use Modules\Shipping\ValueObjects\CarrierRateResult;
use Modules\Shipping\ValueObjects\ShippingRateRequest;

interface CarrierProviderInterface
{
    /**
     * @return array<int, CarrierRateResult>
     */
    public function calculateRates(Carrier $carrier, ShippingRateRequest $request): array;
}
