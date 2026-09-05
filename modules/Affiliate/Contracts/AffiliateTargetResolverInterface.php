<?php

declare(strict_types=1);

namespace Modules\Affiliate\Contracts;

use Modules\Affiliate\Enums\AffiliateTargetType;
use Modules\Affiliate\Exceptions\AffiliateTargetResolutionException;

/**
 * Owner Delta correction §9: target_id is never trusted directly. Every
 * caller resolving a target_type/target_id pair (Referral Code / Campaign
 * creation, attribution, commission evaluation) must go through this one
 * resolver, which proves the target exists AND belongs to the given Tenant.
 */
interface AffiliateTargetResolverInterface
{
    /**
     * @throws AffiliateTargetResolutionException
     */
    public function assertEligible(int $tenantId, AffiliateTargetType $targetType, ?int $targetId): void;

    /**
     * Whether a given Order Item (by its resolved product/category/vendor)
     * falls within the given target scope.
     */
    public function orderItemMatchesTarget(int $tenantId, AffiliateTargetType $targetType, ?int $targetId, int $orderItemId): bool;
}
