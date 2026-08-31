<?php

declare(strict_types=1);

namespace Modules\Promotions\DTOs;

use Modules\Pricing\ValueObjects\MoneyValue;

final class DiscountLine
{
    public function __construct(
        public int $promotionId,
        public string $promotionCode,
        public string $description,
        public MoneyValue $discountAmount,
        public ?string $couponCode = null,
    ) {}
}
