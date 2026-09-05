<?php

declare(strict_types=1);

namespace Modules\Affiliate\Contracts;

use Modules\Affiliate\Models\AffiliateCommissionRule;

/**
 * Resolves the single most-specific matching rule, strictly scoped to the
 * requested currency (Owner Delta correction §14) — never falls back to a
 * rule in a different currency, never converts.
 */
interface AffiliateCommissionRuleResolverInterface
{
    public function resolve(
        int $tenantId,
        int $affiliateId,
        ?int $affiliateCampaignId,
        ?int $categoryId,
        string $currency
    ): ?AffiliateCommissionRule;
}
