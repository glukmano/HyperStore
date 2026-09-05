<?php

declare(strict_types=1);

namespace App\Livewire\Storefront\Account;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Customers\Models\CustomerProfile;
use Modules\Customers\Models\CustomerReferral;
use Modules\Customers\Services\CustomerProfileService;
use Modules\Customers\Services\CustomerReferralService;

/**
 * Phase-19 Final Completion Delta §3: the Customer-facing referral area —
 * personal code/link, and history of who they've referred and its reward
 * state. Purely a view over CustomerReferralService's already-implemented,
 * already-tested qualification policy; no new referral logic lives here.
 */
class ReferralSharePage extends Component
{
    public function render(CustomerReferralService $referralService): View
    {
        $profile = $this->profile();
        $code = $referralService->getOrCreateCode($profile);

        $referrals = CustomerReferral::where('tenant_id', $profile->tenant_id)
            ->where('referrer_customer_profile_id', $profile->id)
            ->with('referred.user')
            ->orderByDesc('id')
            ->get();

        return view('theme::pages.account.referral-share', [
            'code' => $code->code,
            'shareUrl' => url('/refer/'.$code->code),
            'referrals' => $referrals,
        ])->layout('theme::layouts.app', ['title' => __('Referrals')]);
    }

    private function profile(): CustomerProfile
    {
        /** @var User $user */
        $user = auth()->user();

        return app(CustomerProfileService::class)->firstOrCreateFor($user);
    }
}
