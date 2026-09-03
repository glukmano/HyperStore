<?php

declare(strict_types=1);

namespace Modules\Ledger\Exceptions;

use RuntimeException;

class CrossTenantAccessException extends RuntimeException
{
    public static function forAccount(int $tenantId, int $accountId): self
    {
        return new self("Account [{$accountId}] does not belong to tenant [{$tenantId}].");
    }

    public static function forJournal(int $tenantId, string $journalUuid): self
    {
        return new self("Journal entry [{$journalUuid}] does not belong to tenant [{$tenantId}].");
    }
}
