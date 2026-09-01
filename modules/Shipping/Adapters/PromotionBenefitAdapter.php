<?php

declare(strict_types=1);

namespace Modules\Shipping\Adapters;

use Modules\Shipping\Contracts\ShippingPromotionBenefitInterface;
use Modules\Shipping\ValueObjects\FreeShippingBenefitDTO;

class PromotionBenefitAdapter
{
    /**
     * Adapts raw array/external promotion payload to typed domain benefit contract.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): ?ShippingPromotionBenefitInterface
    {
        $type = $payload['type'] ?? '';
        if ($type === 'free_shipping') {
            $applicableCode = isset($payload['applicable_method_code']) ? (string) $payload['applicable_method_code'] : null;

            return new FreeShippingBenefitDTO(applicableMethodCode: $applicableCode);
        }

        return null;
    }
}
