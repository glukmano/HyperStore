<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

use Modules\Pricing\ValueObjects\MoneyValue;

final readonly class CarrierRateResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $carrierCode,
        public string $serviceCode,
        public string $serviceName,
        public MoneyValue $rateAmount,
        public int $transitDaysMin = 1,
        public int $transitDaysMax = 3,
        public array $metadata = []
    ) {}
}
