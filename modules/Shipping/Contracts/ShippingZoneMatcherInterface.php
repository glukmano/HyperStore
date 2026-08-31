<?php

declare(strict_types=1);

namespace Modules\Shipping\Contracts;

use Illuminate\Support\Collection;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;

interface ShippingZoneMatcherInterface
{
    /**
     * @return Collection<int, ShippingZone>
     */
    public function match(ShippingDestination $destination, ShippingContext $context): Collection;
}
