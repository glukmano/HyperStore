<?php

declare(strict_types=1);

namespace Modules\Ledger\Exceptions;

use RuntimeException;

class ImmutableFinancialRecordException extends RuntimeException
{
    public static function forModel(string $modelClass): self
    {
        return new self("Financial record [{$modelClass}] is strictly immutable. UPDATE and DELETE operations are prohibited.");
    }

    public static function forEntity(string $entityName): self
    {
        return new self("Financial record [{$entityName}] is strictly immutable. UPDATE and DELETE operations are prohibited.");
    }
}
