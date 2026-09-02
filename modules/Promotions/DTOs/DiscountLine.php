<?php

declare(strict_types=1);

namespace Modules\Promotions\DTOs;

use Modules\Pricing\ValueObjects\MoneyValue;

final class DiscountLine
{
    /**
     * @param  list<int>  $eligibleCartLineIds
     */
    public function __construct(
        public int $promotionId,
        public string $promotionCode,
        public string $description,
        public MoneyValue $discountAmount,
        public ?string $couponCode = null,
        public array $eligibleCartLineIds = [],
    ) {}

    /**
     * @param  list<int>  $eligibleCartLineIds
     */
    public function withEligibleCartLineIds(array $eligibleCartLineIds): self
    {
        return new self(
            promotionId: $this->promotionId,
            promotionCode: $this->promotionCode,
            description: $this->description,
            discountAmount: $this->discountAmount,
            couponCode: $this->couponCode,
            eligibleCartLineIds: $eligibleCartLineIds,
        );
    }
}
