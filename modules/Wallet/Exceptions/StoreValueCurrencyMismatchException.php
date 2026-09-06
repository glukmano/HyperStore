<?php

declare(strict_types=1);

namespace Modules\Wallet\Exceptions;

/**
 * No implicit FX anywhere in Store Value (D.47/D.51) — a currency mismatch
 * between an account and the requested operation is a hard rejection.
 */
class StoreValueCurrencyMismatchException extends WalletException
{
    public static function forAccount(int $accountId, string $accountCurrency, string $requestedCurrency): self
    {
        return new self("Store Value account [{$accountId}] currency [{$accountCurrency}] does not match requested currency [{$requestedCurrency}] — no implicit FX conversion is performed.");
    }
}
