<?php

declare(strict_types=1);

namespace Modules\Shipping\Contracts;

use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\ShippingRateResult;

interface ShippingRateEngineInterface
{
    public function calculateQuotes(ShippingRateRequest $request): ShippingRateResult;
}
