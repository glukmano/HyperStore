<?php

declare(strict_types=1);

namespace Modules\Shipping\Events;

use Modules\Shipping\Models\Carrier;

final readonly class CarrierConfigured
{
    public function __construct(public Carrier $carrier) {}
}
