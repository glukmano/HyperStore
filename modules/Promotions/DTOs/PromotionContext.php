<?php

declare(strict_types=1);

namespace Modules\Promotions\DTOs;

use DateTimeInterface;

final class PromotionContext
{
    /**
     * @param  array<int, PromotionCartItem>  $items
     * @param  array<int, string>  $couponCodes
     */
    public function __construct(
        public int $tenantId,
        public string $currency,
        public array $items,
        public ?int $storeId = null,
        public ?int $marketId = null,
        public ?int $channelId = null,
        public ?int $customerGroupId = null,
        public ?int $customerId = null,
        public array $couponCodes = [],
        public ?DateTimeInterface $effectiveAt = null,
    ) {}
}
