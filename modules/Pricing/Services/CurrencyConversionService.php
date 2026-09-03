<?php

declare(strict_types=1);

namespace Modules\Pricing\Services;

use Carbon\Carbon;
use InvalidArgumentException;
use Modules\Pricing\Contracts\CurrencyConversionInterface;
use Modules\Pricing\DTOs\CurrencyConversionResult;
use Modules\Pricing\Models\ExchangeRate;
use Modules\Pricing\ValueObjects\MoneyValue;

class CurrencyConversionService implements CurrencyConversionInterface
{
    public function convert(MoneyValue $amount, string $targetCurrency, ?int $tenantId = null): MoneyValue
    {
        return $this->convertWithAudit($amount, $targetCurrency, $tenantId)->convertedAmount;
    }

    public function convertWithAudit(MoneyValue $amount, string $targetCurrency, ?int $tenantId = null): CurrencyConversionResult
    {
        $sourceCurrency = $amount->getCurrencyCode();
        $targetCurrency = strtoupper($targetCurrency);

        if ($sourceCurrency === $targetCurrency) {
            return new CurrencyConversionResult(
                originalAmount: $amount,
                convertedAmount: $amount,
                exchangeRateApplied: '1.00000000',
                exchangeRateId: null,
                isInverseRate: false,
                conversionTimestamp: Carbon::now()->toIso8601String()
            );
        }

        $query = ExchangeRate::query()
            ->where('base_currency', $sourceCurrency)
            ->where('target_currency', $targetCurrency);

        if ($tenantId !== null) {
            $query->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
        }

        /** @var ExchangeRate|null $rateRecord */
        $rateRecord = $query->orderByRaw('tenant_id IS NOT NULL DESC')->first();

        $isInverse = false;
        $rateRecordId = null;

        if ($rateRecord === null) {
            // Check inverse rate
            $inverseQuery = ExchangeRate::query()
                ->where('base_currency', $targetCurrency)
                ->where('target_currency', $sourceCurrency);

            if ($tenantId !== null) {
                $inverseQuery->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
            }

            /** @var ExchangeRate|null $inverseRecord */
            $inverseRecord = $inverseQuery->orderByRaw('tenant_id IS NOT NULL DESC')->first();

            if ($inverseRecord === null) {
                throw new InvalidArgumentException("Exchange rate not configured between [{$sourceCurrency}] and [{$targetCurrency}].");
            }

            $rate = bcdiv('1', (string) $inverseRecord->rate, 8);
            $isInverse = true;
            $rateRecordId = $inverseRecord->id;
        } else {
            $rate = (string) $rateRecord->rate;
            $rateRecordId = $rateRecord->id;
        }

        /** @var numeric-string $sourceDec */
        $sourceDec = (string) $amount->getDecimalAmount();
        /** @var numeric-string $rateStr */
        $rateStr = (string) $rate;
        $convertedDec = bcmul($sourceDec, $rateStr, 6);

        $convertedAmount = MoneyValue::fromDecimal($convertedDec, $targetCurrency);

        return new CurrencyConversionResult(
            originalAmount: $amount,
            convertedAmount: $convertedAmount,
            exchangeRateApplied: $rateStr,
            exchangeRateId: $rateRecordId,
            isInverseRate: $isInverse,
            conversionTimestamp: Carbon::now()->toIso8601String()
        );
    }
}
