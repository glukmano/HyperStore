<?php

declare(strict_types=1);

namespace Modules\Subscriptions\Livewire\Storefront;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Customers\Services\CustomerProfileService;
use Modules\Subscriptions\Models\Subscription;
use Modules\Subscriptions\Services\SubscriptionService;

class SubscriptionsPage extends Component
{
    public function cancelAtPeriodEnd(int $subscriptionId, SubscriptionService $service, CustomerProfileService $profileService): void
    {
        $subscription = $this->ownedSubscription($subscriptionId, $profileService);
        if ($subscription !== null) {
            $service->cancelAtPeriodEnd($subscription);
            session()->flash('success', 'Your subscription will cancel at the end of the current period.');
        }
    }

    private function ownedSubscription(int $subscriptionId, CustomerProfileService $profileService): ?Subscription
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return null;
        }

        $profile = $profileService->firstOrCreateFor($user);

        return Subscription::where('id', $subscriptionId)->where('customer_profile_id', $profile->id)->first();
    }

    public function render(CustomerProfileService $profileService): View
    {
        /** @var User|null $user */
        $user = auth()->user();
        $subscriptions = collect();

        if ($user !== null) {
            $profile = $profileService->firstOrCreateFor($user);
            $subscriptions = Subscription::where('customer_profile_id', $profile->id)
                ->with('plan')
                ->orderByDesc('id')
                ->get();
        }

        return view('subscriptions::livewire.storefront.subscriptions-page', [
            'subscriptions' => $subscriptions,
        ])->layout('theme::layouts.app', ['title' => __('My Subscriptions')]);
    }
}
