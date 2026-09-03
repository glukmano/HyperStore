<?php

declare(strict_types=1);

namespace Modules\Ledger\Exceptions;

use InvalidArgumentException;

class AccountCurrencyMismatchException extends InvalidArgumentException
{
    public static function forAccount(int $accountId, string $accountCurrency, string $journalCurrency): self
    {
        return new self("Account [{$accountId}] is restricted to currency [{$accountCurrency}] and cannot accept journal lines in [{$journalCurrency}].");
    }
}
