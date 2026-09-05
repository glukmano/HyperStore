<?php

declare(strict_types=1);

namespace Modules\Affiliate\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Affiliate\Contracts\AffiliatePayoutServiceInterface;
use Modules\Affiliate\Models\AffiliatePayoutRequest;
use RuntimeException;

class AffiliatePayoutManager extends Component
{
    public function mount(): void
    {
        $this->assertCan('affiliate-payouts.view');
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

    public function approve(int $requestId): void
    {
        $this->assertCan('affiliate-payouts.manage');
        app(AffiliatePayoutServiceInterface::class)->approvePayout($requestId, (int) auth()->id());
        session()->flash('success', 'Payout approved.');
    }

    public function markProcessing(int $requestId): void
    {
        $this->assertCan('affiliate-payouts.manage');
        app(AffiliatePayoutServiceInterface::class)->markProcessing($requestId);
        session()->flash('success', 'Payout marked processing.');
    }

    public function finalize(int $requestId): void
    {
        $this->assertCan('affiliate-payouts.manage');
        app(AffiliatePayoutServiceInterface::class)->finalizePayout($requestId, 'manual-settlement-'.now()->timestamp);
        session()->flash('success', 'Payout finalized.');
    }

    public function cancel(int $requestId): void
    {
        $this->assertCan('affiliate-payouts.manage');
        app(AffiliatePayoutServiceInterface::class)->cancelPayout($requestId);
        session()->flash('success', 'Payout cancelled.');
    }

    public function render(): View
    {
        $requests = AffiliatePayoutRequest::where('tenant_id', $this->tenantId())
            ->orderByDesc('id')
            ->get();

        return view('affiliate::livewire.control-center.affiliate-payout-manager', [
            'requests' => $requests,
        ]);
    }
}
