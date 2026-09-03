<?php

declare(strict_types=1);

namespace Modules\Ledger\Exceptions;

use InvalidArgumentException;

class InvalidJournalLineException extends InvalidArgumentException
{
    public static function forNonPositiveAmount(int $amount): self
    {
        return new self("Journal line amount must be strictly positive (> 0), given [{$amount}].");
    }

    public static function forCurrencyMismatch(string $lineCurrency, string $journalCurrency): self
    {
        return new self("Journal line currency [{$lineCurrency}] does not match journal currency [{$journalCurrency}].");
    }

    public static function forInsufficientLines(): self
    {
        return new self('A journal entry must contain at least two journal lines.');
    }
}
