<?php

declare(strict_types=1);

namespace Modules\Shipping\Events;

use Modules\Shipping\Models\ShippingZone;

final readonly class ShippingZoneCreated
{
    public function __construct(public ShippingZone $zone) {}
}
