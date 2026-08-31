<?php

declare(strict_types=1);

namespace Modules\Pricing\Contracts;

use Modules\Pricing\DTOs\TaxContext;
use Modules\Pricing\DTOs\TaxResult;
use Modules\Pricing\ValueObjects\MoneyValue;

interface TaxCalculatorInterface
{
    public function calculate(MoneyValue $amount, int $taxClassId, TaxContext $context): TaxResult;
}
