<?php

declare(strict_types=1);

namespace Modules\Checkout\Adapters;

use Modules\Promotions\DTOs\PromotionBenefitDTO;
use Modules\Shipping\Contracts\ShippingPromotionBenefitInterface;
use Modules\Shipping\ValueObjects\FreeShippingBenefitDTO;

class CheckoutPromotionBenefitAdapter
{
    /**
     * Adapts generic PromotionBenefitDTOs from the Promotions domain into typed ShippingPromotionBenefitInterface instances for the Shipping domain.
     *
     * @param  list<PromotionBenefitDTO>  $benefits
     * @return list<ShippingPromotionBenefitInterface>
     */
    public static function adapt(array $benefits): array
    {
        $adapted = [];
        foreach ($benefits as $benefit) {
            if ($benefit->type === 'free_shipping') {
                $applicableCode = isset($benefit->parameters['applicable_method_code']) ? (string) $benefit->parameters['applicable_method_code'] : null;
                $adapted[] = new FreeShippingBenefitDTO(
                    applicableMethodCode: $applicableCode,
                    description: $benefit->description ?? 'Free shipping promotion applied'
                );
            }
        }

        return $adapted;
    }
}
