<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Exceptions;

use RuntimeException;

final class SaasPlanLimitReductionException extends RuntimeException
{
    public static function tenantUsageExceedsProposedLimit(int $tenantId, string $resourceKey, int $currentUsage, int $proposedLimit): self
    {
        return new self("Cannot reduce hard limit for [{$resourceKey}] to [{$proposedLimit}]: Tenant [{$tenantId}] currently has committed usage of [{$currentUsage}].");
    }
}
