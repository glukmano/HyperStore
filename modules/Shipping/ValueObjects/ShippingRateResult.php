<?php

declare(strict_types=1);

namespace Modules\Shipping\ValueObjects;

use Illuminate\Support\Collection;
use Modules\Shipping\Models\ShippingZone;

final readonly class ShippingRateResult
{
    /**
     * @param  Collection<int, ShippingRateQuote>  $quotes
     * @param  array<int, ProviderError>  $errors
     * @param  array<int, string>  $warnings
     * @param  Collection<int, ShippingZone>  $matchedZones
     */
    public function __construct(
        public Collection $quotes,
        public string $outcome,
        public array $errors = [],
        public array $warnings = [],
        public Collection $matchedZones = new Collection
    ) {}

    public function isSuccess(): bool
    {
        return ($this->outcome === ShippingRateOutcome::SUCCESS && $this->quotes->isNotEmpty())
            || $this->outcome === ShippingRateOutcome::NO_SHIPPING_REQUIRED;
    }
}
