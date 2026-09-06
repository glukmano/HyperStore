<?php

declare(strict_types=1);

namespace Modules\Wallet\Livewire\Storefront;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Customers\Services\CustomerProfileService;
use Modules\Wallet\Contracts\StoreValueServiceInterface;
use Modules\Wallet\Models\StoreValueAccount;
use Modules\Wallet\Models\StoreValueEntry;

class WalletPage extends Component
{
    public function render(CustomerProfileService $profileService, StoreValueServiceInterface $storeValueService): View
    {
        /** @var User|null $user */
        $user = auth()->user();

        $accounts = collect();
        $balances = [];
        $entries = collect();

        if ($user !== null) {
            $profile = $profileService->firstOrCreateFor($user);

            $accounts = StoreValueAccount::where('customer_profile_id', $profile->id)
                ->whereIn('instrument_type', ['wallet', 'store_credit', 'gift_card'])
                ->get();

            foreach ($accounts as $account) {
                $balances[$account->id] = $storeValueService->getAvailableBalanceMinor($account);
            }

            $entries = StoreValueEntry::whereIn('store_value_account_id', $accounts->pluck('id'))
                ->whereIn('entry_type', ['issue', 'capture', 'refund_credit', 'manual_adjustment_credit', 'manual_adjustment_debit'])
                ->orderByDesc('id')
                ->limit(50)
                ->get();
        }

        return view('wallet::livewire.storefront.wallet-page', [
            'accounts' => $accounts,
            'balances' => $balances,
            'entries' => $entries,
        ]);
    }
}
