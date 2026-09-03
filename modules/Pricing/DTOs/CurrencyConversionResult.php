<?php

declare(strict_types=1);

namespace Modules\Pricing\DTOs;

use Modules\Pricing\ValueObjects\MoneyValue;

final readonly class CurrencyConversionResult
{
    public function __construct(
        public MoneyValue $originalAmount,
        public MoneyValue $convertedAmount,
        public string $exchangeRateApplied,
        public ?int $exchangeRateId,
        public bool $isInverseRate,
        public string $conversionTimestamp
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAuditSnapshot(): array
    {
        return [
            'original_amount_minor' => $this->originalAmount->getMinorAmount(),
            'original_currency' => $this->originalAmount->getCurrencyCode(),
            'converted_amount_minor' => $this->convertedAmount->getMinorAmount(),
            'converted_currency' => $this->convertedAmount->getCurrencyCode(),
            'exchange_rate' => $this->exchangeRateApplied,
            'exchange_rate_id' => $this->exchangeRateId,
            'is_inverse_rate' => $this->isInverseRate,
            'conversion_timestamp' => $this->conversionTimestamp,
        ];
    }
}
