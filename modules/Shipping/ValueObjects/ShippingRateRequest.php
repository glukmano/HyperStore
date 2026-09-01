<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

use Modules\Shipping\Contracts\ShippingPromotionBenefitInterface;

final readonly class ShippingRateRequest
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<int, ShippingPromotionBenefitInterface>  $promotionBenefits
     */
    public function __construct(
        public ShippingContext $context,
        public ShippingDestination $destination,
        public array $lines,
        public array $promotionBenefits = [],
        public ?bool $hasUnfulfillableItems = null
    ) {}
}
