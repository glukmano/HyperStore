<?php

declare(strict_types=1);

namespace Modules\Affiliate\Contracts;

use Modules\Affiliate\Models\AffiliateAttribution;
use Modules\Order\Models\Order;

/**
 * Owner Delta correction §2: attribution is frozen once, at the Order
 * boundary, and never recomputed from live Click/Campaign/Rule state
 * afterward. Payment=paid only ever ACTIVATES an already-frozen attribution
 * (see AffiliateConversion) — it never calls back into this resolver.
 */
interface AffiliateAttributionServiceInterface
{
    /**
     * Idempotent: replaying for an Order that already has an active
     * attribution is a no-op (returns the existing row untouched).
     */
    public function freezeAttributionForOrder(Order $order, ?string $visitorTokenHash, ?string $couponCode): ?AffiliateAttribution;

    /**
     * Owner Delta correction §6: manual re-attribution never mutates
     * history. Before economic accrual this simply supersedes the frozen
     * attribution; after accrual, the caller (AffiliateConversionService) is
     * responsible for the compensating reversal — this method only ever
     * changes which attribution is "active" for the Order.
     */
    public function manuallyReattribute(Order $order, int $newAffiliateId, int $actingUserId, ?int $newAffiliateCampaignId = null): AffiliateAttribution;
}
