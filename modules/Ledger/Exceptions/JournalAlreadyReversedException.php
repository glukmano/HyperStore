<?php

declare(strict_types=1);

namespace Modules\Ledger\Exceptions;

use RuntimeException;

class JournalAlreadyReversedException extends RuntimeException
{
    public static function forJournal(string $journalUuid): self
    {
        return new self("Journal entry [{$journalUuid}] has already been reversed and cannot be reversed again.");
    }
}
