<?php

declare(strict_types=1);

namespace Modules\Affiliate\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Affiliate\Contracts\AffiliatePayableSubledgerServiceInterface;
use Modules\Affiliate\Enums\AffiliateStatus;
use Modules\Affiliate\Models\Affiliate;
use Modules\Affiliate\Models\AffiliateFraudFlag;
use RuntimeException;

class AffiliateManager extends Component
{
    public function mount(): void
    {
        $this->assertCan('affiliates.view');
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

    public function approve(int $affiliateId): void
    {
        $this->assertCan('affiliates.manage');

        $affiliate = Affiliate::where('tenant_id', $this->tenantId())->findOrFail($affiliateId);
        $affiliate->status = AffiliateStatus::Active;
        $affiliate->approved_at = CarbonImmutable::now();
        $affiliate->approved_by_user_id = auth()->id() !== null ? (int) auth()->id() : null;
        $affiliate->save();

        session()->flash('success', 'Affiliate approved.');
    }

    public function suspend(int $affiliateId): void
    {
        $this->assertCan('affiliates.manage');

        $affiliate = Affiliate::where('tenant_id', $this->tenantId())->findOrFail($affiliateId);
        $affiliate->status = AffiliateStatus::Suspended;
        $affiliate->save();

        session()->flash('success', 'Affiliate suspended.');
    }

    public function reject(int $affiliateId): void
    {
        $this->assertCan('affiliates.manage');

        $affiliate = Affiliate::where('tenant_id', $this->tenantId())->findOrFail($affiliateId);
        $affiliate->status = AffiliateStatus::Rejected;
        $affiliate->save();

        session()->flash('success', 'Affiliate rejected.');
    }

    public function resolveFlag(int $flagId, string $resolution): void
    {
        $this->assertCan('affiliates.manage');

        $flag = AffiliateFraudFlag::where('tenant_id', $this->tenantId())->findOrFail($flagId);
        $flag->resolved_at = CarbonImmutable::now();
        $flag->resolution = $resolution;
        $flag->save();

        session()->flash('success', 'Fraud flag resolved.');
    }

    public function render(): View
    {
        $tenantId = $this->tenantId();

        $affiliates = Affiliate::where('tenant_id', $tenantId)
            ->orderByDesc('applied_at')
            ->get();

        $subledger = app(AffiliatePayableSubledgerServiceInterface::class);
        $balances = [];
        foreach ($affiliates as $affiliate) {
            $balances[$affiliate->id] = $subledger->getBalances($tenantId, (int) $affiliate->id, $affiliate->payout_currency);
        }

        $openFlags = AffiliateFraudFlag::where('tenant_id', $tenantId)
            ->whereNull('resolved_at')
            ->orderByDesc('detected_at')
            ->get();

        return view('affiliate::livewire.control-center.affiliate-manager', [
            'affiliates' => $affiliates,
            'balances' => $balances,
            'openFlags' => $openFlags,
        ]);
    }
}
