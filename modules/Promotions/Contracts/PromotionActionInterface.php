<?php

declare(strict_types=1);

namespace Modules\Promotions\Contracts;

use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Promotions\DTOs\DiscountLine;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Promotions\Models\Promotion;

interface PromotionActionInterface
{
    public function getType(): string;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function apply(Promotion $promotion, PromotionContext $context, array $parameters, MoneyValue $currentTotal): ?DiscountLine;
}
