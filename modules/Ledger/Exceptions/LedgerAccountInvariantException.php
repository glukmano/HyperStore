<?php

declare(strict_types=1);

namespace Modules\Ledger\Exceptions;

use RuntimeException;

class LedgerAccountInvariantException extends RuntimeException
{
    public static function cannotMutateSystemField(string $field): self
    {
        return new self("Cannot mutate system account field [{$field}]. Accounting classification is immutable.");
    }

    public static function cannotMutatePostedField(string $field): self
    {
        return new self("Cannot mutate field [{$field}] on account with existing journal lines. Accounting classification is immutable.");
    }

    public static function cannotArchiveRequiredSystemAccount(string $role): self
    {
        return new self("Cannot archive or deactivate required system account with role [{$role}].");
    }

    public static function invalidClassification(string $role, string $expectedType, string $actualType): self
    {
        return new self("Invalid classification for role [{$role}]: expected type [{$expectedType}], got [{$actualType}].");
    }
}
