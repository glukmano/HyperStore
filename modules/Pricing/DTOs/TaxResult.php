<?php

declare(strict_types=1);

namespace Modules\Pricing\DTOs;

use Modules\Pricing\ValueObjects\MoneyValue;

final class TaxResult
{
    /**
     * @param  array<int, array{rate_name: string, percentage: string, amount_minor: int}>  $appliedRates
     */
    public function __construct(
        public MoneyValue $netAmount,
        public MoneyValue $taxAmount,
        public MoneyValue $grossAmount,
        public array $appliedRates = [],
    ) {}
}
