<?php

declare(strict_types=1);

namespace Modules\Shipping\Events;

use Modules\Shipping\Models\ShippingMethod;

final readonly class ShippingMethodChanged
{
    public function __construct(
        public ShippingMethod $method,
        public string $changeType // created, updated, deleted, status_toggled
    ) {}
}
