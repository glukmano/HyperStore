<?php

declare(strict_types=1);

namespace Modules\Shipping\Contracts;

use Illuminate\Support\Collection;
use Modules\Shipping\ValueObjects\ShippingRateQuote;
use Modules\Shipping\ValueObjects\ShippingRateRequest;

interface ShippingRateEngineInterface
{
    /**
     * @return Collection<int, ShippingRateQuote>
     */
    public function calculateQuotes(ShippingRateRequest $request): Collection;
}
