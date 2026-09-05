<?php

declare(strict_types=1);

namespace App\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Customers\Models\CustomerReferral;
use Modules\Promotions\Models\LoyaltyPointEntry;
use RuntimeException;

/**
 * Phase-19 Final Completion Delta §2: the smallest read-only Control Center
 * visibility into Customer referral status — no Customer detail/profile
 * Control Center screen exists yet anywhere in the platform to extend, so
 * this is a standalone list, deliberately read-only (no manage actions;
 * referral qualification/reward is a fully automated, audited economic
 * process — see Modules\Customers\Services\CustomerReferralService).
 */
class CustomerReferralManager extends Component
{
    private function tenantId(): int
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId();
        if ($tenantId === null) {
            throw new RuntimeException('Tenant context required.');
        }

        return (int) $tenantId;
    }

    public function mount(): void
    {
        if (! auth()->user()?->can('customers.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }

    public function render(): View
    {
        $referrals = CustomerReferral::where('tenant_id', $this->tenantId())
            ->with(['referrer.user', 'referred.user', 'qualifyingOrder'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $rewardStatusByReferralId = LoyaltyPointEntry::where('tenant_id', $this->tenantId())
            ->where('source_type', 'customer_referral')
            ->whereIn('source_uuid', $referrals->map(fn (CustomerReferral $r) => 'customer_referral:'.$r->id))
            ->get()
            ->keyBy(fn (LoyaltyPointEntry $e) => (int) str_replace('customer_referral:', '', $e->source_uuid))
            ->map(fn (LoyaltyPointEntry $e) => $e->availability_status);

        return view('livewire.control-center.customer-referral-manager', [
            'referrals' => $referrals,
            'rewardStatusByReferralId' => $rewardStatusByReferralId,
        ]);
    }
}
