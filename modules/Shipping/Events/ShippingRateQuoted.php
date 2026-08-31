<?php

declare(strict_types=1);

namespace Modules\Shipping\Events;

use Modules\Shipping\ValueObjects\ShippingRateRequest;

final readonly class ShippingRateQuoted
{
    public function __construct(
        public ShippingRateRequest $request,
        public int $quotesCount
    ) {}
}
