<?php

declare(strict_types=1);

namespace Modules\Pricing\Contracts;

use Modules\Pricing\DTOs\CurrencyConversionResult;
use Modules\Pricing\ValueObjects\MoneyValue;

interface CurrencyConversionInterface
{
    public function convert(MoneyValue $amount, string $targetCurrency, ?int $tenantId = null): MoneyValue;

    public function convertWithAudit(MoneyValue $amount, string $targetCurrency, ?int $tenantId = null): CurrencyConversionResult;
}
