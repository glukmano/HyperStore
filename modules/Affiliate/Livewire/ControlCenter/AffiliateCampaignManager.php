<?php

declare(strict_types=1);

namespace Modules\Affiliate\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;
use Modules\Affiliate\Enums\AffiliateAttributionStrategy;
use Modules\Affiliate\Enums\AffiliateTargetType;
use Modules\Affiliate\Exceptions\AffiliateTargetResolutionException;
use Modules\Affiliate\Models\Affiliate;
use Modules\Affiliate\Models\AffiliateCampaign;
use Modules\Affiliate\Models\AffiliateReferralCode;
use Modules\Affiliate\Services\AffiliateTargetResolver;
use RuntimeException;

class AffiliateCampaignManager extends Component
{
    public string $name = '';

    public string $target_type = 'platform';

    public ?int $target_id = null;

    public string $attribution_strategy = 'last_click';

    public int $attribution_window_days = 30;

    public ?int $referral_affiliate_id = null;

    public ?int $referral_campaign_id = null;

    public function mount(): void
    {
        $this->assertCan('marketing-campaigns.view');
    }

    private function assertCan(string $permission): void
    {
        if (! auth()->user()?->can($permission) && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }

    private function tenantId(): int
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId();
        if ($tenantId === null) {
            throw new RuntimeException('Tenant context required.');
        }

        return (int) $tenantId;
    }

    public function createCampaign(): void
    {
        $this->assertCan('marketing-campaigns.manage');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'target_type' => ['required', 'string'],
            'target_id' => ['nullable', 'integer'],
            'attribution_strategy' => ['required', 'string'],
            'attribution_window_days' => ['required', 'integer', 'min:1'],
        ]);

        $tenantId = $this->tenantId();
        $targetType = AffiliateTargetType::from($validated['target_type']);

        try {
            app(AffiliateTargetResolver::class)->assertEligible($tenantId, $targetType, $validated['target_id']);
        } catch (AffiliateTargetResolutionException $e) {
            $this->addError('target_id', $e->getMessage());

            return;
        }

        AffiliateCampaign::create([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'target_type' => $targetType,
            'target_id' => $validated['target_id'],
            'attribution_strategy' => AffiliateAttributionStrategy::from($validated['attribution_strategy']),
            'attribution_window_days' => $validated['attribution_window_days'],
            'is_active' => true,
        ]);

        session()->flash('success', 'Campaign created.');
        $this->reset(['name', 'target_id']);
    }

    public function deactivate(int $campaignId): void
    {
        $this->assertCan('marketing-campaigns.manage');

        $campaign = AffiliateCampaign::where('tenant_id', $this->tenantId())->findOrFail($campaignId);
        $campaign->is_active = false;
        $campaign->save();

        session()->flash('success', 'Campaign deactivated.');
    }

    public function generateReferralCode(): void
    {
        $this->assertCan('marketing-campaigns.manage');

        $validated = $this->validate([
            'referral_affiliate_id' => ['required', 'integer'],
        ]);

        $tenantId = $this->tenantId();
        /** @var Affiliate $affiliate */
        $affiliate = Affiliate::where('tenant_id', $tenantId)->findOrFail($validated['referral_affiliate_id']);

        $campaign = $this->referral_campaign_id !== null
            ? AffiliateCampaign::where('tenant_id', $tenantId)->find($this->referral_campaign_id)
            : null;

        AffiliateReferralCode::create([
            'tenant_id' => $tenantId,
            'affiliate_id' => $affiliate->id,
            'affiliate_campaign_id' => $campaign?->id,
            'code' => Str::upper(Str::random(8)),
            'target_type' => $campaign !== null ? $campaign->target_type : AffiliateTargetType::Platform,
            'target_id' => $campaign !== null ? $campaign->target_id : null,
            'is_active' => true,
        ]);

        session()->flash('success', 'Referral code generated.');
    }

    public function render(): View
    {
        $tenantId = $this->tenantId();

        return view('affiliate::livewire.control-center.affiliate-campaign-manager', [
            'campaigns' => AffiliateCampaign::where('tenant_id', $tenantId)->orderByDesc('id')->get(),
            'affiliates' => Affiliate::where('tenant_id', $tenantId)->orderBy('display_name')->get(),
            'referralCodes' => AffiliateReferralCode::where('tenant_id', $tenantId)->orderByDesc('id')->limit(50)->get(),
            'targetTypes' => AffiliateTargetType::cases(),
            'strategies' => AffiliateAttributionStrategy::cases(),
        ]);
    }
}
