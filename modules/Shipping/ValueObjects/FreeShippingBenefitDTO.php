<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

use Modules\Shipping\Contracts\ShippingPromotionBenefitInterface;

final readonly class FreeShippingBenefitDTO implements ShippingPromotionBenefitInterface
{
    public function __construct(
        public ?string $applicableMethodCode = null,
        public ?string $description = 'Free shipping promotion applied'
    ) {}

    public function isFreeShipping(): bool
    {
        return true;
    }

    public function getApplicableMethodCode(): ?string
    {
        return $this->applicableMethodCode;
    }
}
