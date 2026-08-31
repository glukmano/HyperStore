<?php

declare(strict_types=1);

namespace Modules\Shipping\Contracts;

use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\ValueObjects\RateBreakdown;
use Modules\Shipping\ValueObjects\ShippingRateRequest;

interface RateCalculatorInterface
{
    public function calculate(ShippingMethod $method, ShippingZone $zone, ShippingRateRequest $request): ?RateBreakdown;
}
