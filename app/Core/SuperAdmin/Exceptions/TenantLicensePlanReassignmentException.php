<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Exceptions;

use RuntimeException;

final class TenantLicensePlanReassignmentException extends RuntimeException
{
    public static function usageExceedsTargetPlanLimits(int $tenantId, string $resourceKey, int $currentUsage, int $targetLimit): self
    {
        return new self("Cannot reassign Tenant [{$tenantId}] to target plan: committed usage [{$currentUsage}] for [{$resourceKey}] exceeds target plan limit [{$targetLimit}].");
    }

    public static function planNotActive(int $planId, string $status): self
    {
        return new self("Target plan [{$planId}] is not active (status: {$status}); reassignment rejected.");
    }
}
