<?php

declare(strict_types=1);

namespace App\Livewire\Storefront\Account;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Customers\Models\CustomerProfile;
use Modules\Customers\Services\CustomerProfileService;

class NotificationPreferencesPage extends Component
{
    public bool $priceDropEmail = true;

    public bool $backInStockEmail = true;

    public function mount(CustomerProfileService $profileService): void
    {
        $profile = $this->profile($profileService);
        $prefs = $profile->notification_preferences ?? [];

        $this->priceDropEmail = $prefs['price_drop']['mail'] ?? true;
        $this->backInStockEmail = $prefs['back_in_stock']['mail'] ?? true;
    }

    public function save(CustomerProfileService $profileService): void
    {
        $profile = $this->profile($profileService);

        $profile->notification_preferences = [
            'price_drop' => ['database' => true, 'mail' => $this->priceDropEmail],
            'back_in_stock' => ['database' => true, 'mail' => $this->backInStockEmail],
        ];
        $profile->save();

        session()->flash('success', __('Preferences saved.'));
    }

    public function render(): View
    {
        return view('theme::pages.account.notification-preferences')
            ->layout('theme::layouts.app', ['title' => __('Notification Preferences')]);
    }

    private function profile(CustomerProfileService $profileService): CustomerProfile
    {
        /** @var User $user */
        $user = auth()->user();

        return $profileService->firstOrCreateFor($user);
    }
}
