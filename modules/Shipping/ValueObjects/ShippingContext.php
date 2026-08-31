<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

final readonly class ShippingContext
{
    public function __construct(
        public int $tenantId,
        public ?int $storeId = null,
        public ?int $marketId = null,
        public ?int $channelId = null,
        public string $currency = 'CHF'
    ) {}
}
