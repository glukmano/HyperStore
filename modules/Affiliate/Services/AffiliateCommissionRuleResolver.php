<?php

declare(strict_types=1);

namespace Modules\Affiliate\Services;

use Modules\Affiliate\Contracts\AffiliateCommissionRuleResolverInterface;
use Modules\Affiliate\Models\AffiliateCommissionRule;

/**
 * Most-specific-first resolution, strictly scoped to the requested currency
 * (Owner Delta correction §14) — a rule in a different currency is simply
 * not a candidate, never a fallback-with-conversion.
 */
final class AffiliateCommissionRuleResolver implements AffiliateCommissionRuleResolverInterface
{
    public function resolve(
        int $tenantId,
        int $affiliateId,
        ?int $affiliateCampaignId,
        ?int $categoryId,
        string $currency
    ): ?AffiliateCommissionRule {
        $candidates = [
            ['affiliate_id' => $affiliateId, 'affiliate_campaign_id' => $affiliateCampaignId, 'category_id' => $categoryId],
            ['affiliate_id' => $affiliateId, 'affiliate_campaign_id' => $affiliateCampaignId, 'category_id' => null],
            ['affiliate_id' => $affiliateId, 'affiliate_campaign_id' => null, 'category_id' => $categoryId],
            ['affiliate_id' => $affiliateId, 'affiliate_campaign_id' => null, 'category_id' => null],
            ['affiliate_id' => null, 'affiliate_campaign_id' => $affiliateCampaignId, 'category_id' => null],
            ['affiliate_id' => null, 'affiliate_campaign_id' => null, 'category_id' => null],
        ];

        foreach ($candidates as $shape) {
            $query = AffiliateCommissionRule::where('tenant_id', $tenantId)
                ->where('currency', $currency)
                ->where('is_active', true);

            $query = $shape['affiliate_id'] === null
                ? $query->whereNull('affiliate_id')
                : $query->where('affiliate_id', $shape['affiliate_id']);

            $query = $shape['affiliate_campaign_id'] === null
                ? $query->whereNull('affiliate_campaign_id')
                : $query->where('affiliate_campaign_id', $shape['affiliate_campaign_id']);

            $query = $shape['category_id'] === null
                ? $query->whereNull('category_id')
                : $query->where('category_id', $shape['category_id']);

            $rule = $query->first();
            if ($rule !== null) {
                return $rule;
            }
        }

        return null;
    }
}
