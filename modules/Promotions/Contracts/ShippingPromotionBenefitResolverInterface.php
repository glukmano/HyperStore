<?php

declare(strict_types=1);

namespace Modules\Promotions\Contracts;

use Modules\Promotions\DTOs\PromotionContext;
use Modules\Shipping\Contracts\ShippingPromotionBenefitInterface;

interface ShippingPromotionBenefitResolverInterface
{
    /**
     * Resolves typed shipping promotion benefits for the given promotion context.
     *
     * @return array<int, ShippingPromotionBenefitInterface>
     */
    public function resolveBenefits(PromotionContext $context): array;
}
