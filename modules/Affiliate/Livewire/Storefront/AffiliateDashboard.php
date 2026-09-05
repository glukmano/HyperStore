<?php

declare(strict_types=1);

namespace Modules\Affiliate\Livewire\Storefront;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Affiliate\Contracts\AffiliatePayableSubledgerServiceInterface;
use Modules\Affiliate\Contracts\AffiliatePayoutServiceInterface;
use Modules\Affiliate\Enums\AffiliateStatus;
use Modules\Affiliate\Models\Affiliate;
use Modules\Affiliate\Models\AffiliateAttribution;
use Modules\Affiliate\Models\AffiliateReferralCode;

class AffiliateDashboard extends Component
{
    public function requestPayout(int $amountMinor): void
    {
        $affiliate = $this->currentAffiliate();
        abort_if($affiliate === null || $affiliate->status !== AffiliateStatus::Active, 403);

        app(AffiliatePayoutServiceInterface::class)->requestPayout(
            (int) $affiliate->tenant_id,
            (int) $affiliate->id,
            $amountMinor,
            $affiliate->payout_currency,
        );

        session()->flash('success', 'Payout requested.');
    }

    private function currentAffiliate(): ?Affiliate
    {
        $user = auth()->user();
        $tenantId = app(ContextManager::class)->getTenant()->getId();
        if ($user === null || $tenantId === null) {
            return null;
        }

        return Affiliate::where('tenant_id', $tenantId)->where('user_id', $user->id)->first();
    }

    public function render(): View
    {
        $affiliate = $this->currentAffiliate();

        if ($affiliate === null) {
            return view('affiliate::livewire.storefront.affiliate-dashboard', ['affiliate' => null]);
        }

        $balances = app(AffiliatePayableSubledgerServiceInterface::class)
            ->getBalances((int) $affiliate->tenant_id, (int) $affiliate->id, $affiliate->payout_currency);

        $referralCodes = AffiliateReferralCode::where('tenant_id', $affiliate->tenant_id)
            ->where('affiliate_id', $affiliate->id)
            ->where('is_active', true)
            ->get();

        $conversionCount = AffiliateAttribution::where('tenant_id', $affiliate->tenant_id)
            ->where('affiliate_id', $affiliate->id)
            ->whereNull('superseded_by_attribution_id')
            ->count();

        return view('affiliate::livewire.storefront.affiliate-dashboard', [
            'affiliate' => $affiliate,
            'balances' => $balances,
            'referralCodes' => $referralCodes,
            'conversionCount' => $conversionCount,
            'trackBaseUrl' => url('/r'),
        ]);
    }
}
