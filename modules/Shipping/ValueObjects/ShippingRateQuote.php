<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

use Modules\Pricing\ValueObjects\MoneyValue;

final readonly class ShippingRateQuote
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $methodId,
        public string $methodCode,
        public string $title,
        public ?string $description,
        public MoneyValue $amount,
        public RateBreakdown $breakdown,
        public int $methodPriority = 0,
        public ?string $carrierCode = null,
        public ?string $serviceCode = null,
        public int $estimatedDaysMin = 1,
        public int $estimatedDaysMax = 3,
        public array $metadata = []
    ) {}
}
