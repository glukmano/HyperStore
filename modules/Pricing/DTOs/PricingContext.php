<?php

declare(strict_types=1);

namespace Modules\Pricing\DTOs;

use DateTimeInterface;

final class PricingContext
{
    public function __construct(
        public int $tenantId,
        public string $currency,
        public ?int $storeId = null,
        public ?int $marketId = null,
        public ?int $channelId = null,
        public ?int $customerGroupId = null,
        public ?DateTimeInterface $effectiveAt = null,
    ) {}
}
