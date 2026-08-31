<?php

declare(strict_types=1);

namespace Modules\Promotions\Contracts;

use Modules\Promotions\DTOs\PromotionContext;

interface PromotionConditionInterface
{
    public function getType(): string;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function evaluate(PromotionContext $context, array $parameters): bool;
}
