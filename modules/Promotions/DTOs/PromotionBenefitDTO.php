<?php

declare(strict_types=1);

namespace Modules\Promotions\DTOs;

final readonly class PromotionBenefitDTO
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public int $promotionId,
        public string $type,
        public array $parameters = [],
        public ?string $description = null,
        public ?string $couponCode = null
    ) {}
}
