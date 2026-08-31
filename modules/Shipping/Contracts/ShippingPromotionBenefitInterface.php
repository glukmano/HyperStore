<?php

declare(strict_types=1);

namespace Modules\Shipping\Contracts;

interface ShippingPromotionBenefitInterface
{
    public function isFreeShipping(): bool;

    public function getApplicableMethodCode(): ?string;
}
