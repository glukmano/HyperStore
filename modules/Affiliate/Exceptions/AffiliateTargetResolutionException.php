<?php

declare(strict_types=1);

namespace Modules\Affiliate\Exceptions;

/**
 * Thrown whenever a target_type/target_id pair does not resolve to an
 * existing, Tenant-eligible entity (Owner Delta correction §9) — target_id
 * is never trusted directly.
 */
final class AffiliateTargetResolutionException extends AffiliateException
{
    public static function notFoundInTenant(string $targetType, int $targetId, int $tenantId): self
    {
        return new self("Target '{$targetType}:{$targetId}' does not exist or is not eligible for tenant {$tenantId}.");
    }
}
