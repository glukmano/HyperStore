<?php

declare(strict_types=1);

namespace Modules\Ledger\Exceptions;

use RuntimeException;

class AccountInUseException extends RuntimeException
{
    public static function cannotDelete(int $accountId): self
    {
        return new self("Ledger account [{$accountId}] has existing journal lines and cannot be deleted.");
    }

    public static function cannotMutateSystemRole(int $accountId): self
    {
        return new self("Ledger account [{$accountId}] is a system-managed account. Its system role and classification cannot be altered.");
    }
}
