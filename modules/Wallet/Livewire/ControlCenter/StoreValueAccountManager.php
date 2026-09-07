<?php

declare(strict_types=1);

namespace Modules\Wallet\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Wallet\Contracts\StoreValueServiceInterface;
use Modules\Wallet\Models\StoreValueAccount;
use RuntimeException;

class StoreValueAccountManager extends Component
{
    public int $adjustAccountId = 0;

    public string $adjustDirection = 'credit';

    public string $adjustAmount = '';

    public string $adjustReason = '';

    public function mount(): void
    {
        $this->assertCan('wallet.accounts.view');
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

    public function submitAdjustment(StoreValueServiceInterface $storeValueService): void
    {
        $this->assertCan('wallet.accounts.manage');

        $this->validate([
            'adjustAccountId' => 'required|integer|min:1',
            'adjustDirection' => 'required|in:credit,debit',
            'adjustAmount' => 'required|numeric|min:0.01',
            'adjustReason' => 'required|string|min:3|max:255',
        ]);

        /** @var StoreValueAccount $account */
        $account = StoreValueAccount::where('tenant_id', $this->tenantId())->findOrFail($this->adjustAccountId);
        $amountMinor = (int) round(((float) $this->adjustAmount) * 100);
        $sourceUuid = 'cc-adjust-'.now()->timestamp.'-'.$account->id.'-'.bin2hex(random_bytes(4));

        if ($this->adjustDirection === 'credit') {
            $storeValueService->manualAdjustmentCredit($account, $amountMinor, $sourceUuid, $this->adjustReason);
        } else {
            $storeValueService->manualAdjustmentDebit($account, $amountMinor, $sourceUuid, $this->adjustReason);
        }

        $this->reset(['adjustAccountId', 'adjustAmount', 'adjustReason']);
        session()->flash('success', 'Store Value adjustment applied.');
    }

    public function render(StoreValueServiceInterface $storeValueService): View
    {
        $accounts = StoreValueAccount::where('tenant_id', $this->tenantId())
            ->with('customerProfile.user')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $balances = [];
        foreach ($accounts as $account) {
            $balances[$account->id] = $storeValueService->getAvailableBalanceMinor($account);
        }

        return view('wallet::livewire.control-center.store-value-account-manager', [
            'accounts' => $accounts,
            'balances' => $balances,
        ])->layout('layouts.control-center', ['title' => 'Store Value Accounts']);
    }
}
