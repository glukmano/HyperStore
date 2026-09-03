<?php

declare(strict_types=1);

namespace Modules\Ledger\Exceptions;

use RuntimeException;

class UnbalancedJournalException extends RuntimeException
{
    public static function withAmounts(int $debitTotal, int $creditTotal, string $currency): self
    {
        return new self("Unbalanced journal entry in currency [{$currency}]: debits [{$debitTotal}] !== credits [{$creditTotal}].");
    }
}
