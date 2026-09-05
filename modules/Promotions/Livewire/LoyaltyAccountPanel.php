<?php

declare(strict_types=1);

namespace Modules\Promotions\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Customers\Models\CustomerProfile;
use Modules\Customers\Services\CustomerProfileService;
use Modules\Promotions\Models\LoyaltyPointEntry;
use Modules\Promotions\Services\LoyaltyService;

/**
 * Phase-19 Final Completion Delta §4 (ACCOUNT): available points, pending
 * points, and expiry information — every figure is recomputed live from the
 * append-only ledger via LoyaltyService, never a cached/stored total.
 */
class LoyaltyAccountPanel extends Component
{
    public function render(LoyaltyService $loyaltyService): View
    {
        $profile = $this->profile();

        $available = $loyaltyService->getAvailableBalance($profile);

        $pending = (int) LoyaltyPointEntry::where('tenant_id', $profile->tenant_id)
            ->where('customer_profile_id', $profile->id)
            ->where('entry_type', 'earned')
            ->where('availability_status', 'pending')
            ->sum('points');

        $nextExpiry = LoyaltyPointEntry::where('tenant_id', $profile->tenant_id)
            ->where('customer_profile_id', $profile->id)
            ->where('entry_type', 'earned')
            ->where('availability_status', 'available')
            ->whereNotNull('expires_at')
            ->orderBy('expires_at')
            ->first();

        $history = LoyaltyPointEntry::where('tenant_id', $profile->tenant_id)
            ->where('customer_profile_id', $profile->id)
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        return view('promotions::livewire.loyalty-account-panel', [
            'available' => $available,
            'pending' => $pending,
            'nextExpiry' => $nextExpiry,
            'history' => $history,
        ])->layout('theme::layouts.app', ['title' => __('Loyalty Points')]);
    }

    private function profile(): CustomerProfile
    {
        /** @var User $user */
        $user = auth()->user();

        return app(CustomerProfileService::class)->firstOrCreateFor($user);
    }
}
