<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Livewire\Storefront;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\URL;
use Livewire\Component;
use Modules\Customers\Services\CustomerProfileService;
use Modules\DigitalDelivery\Models\CustomerEntitlement;

class DigitalDownloadsPage extends Component
{
    public function render(CustomerProfileService $profileService): View
    {
        /** @var User|null $user */
        $user = auth()->user();

        $entitlements = collect();
        $downloadUrls = [];

        if ($user !== null) {
            $profile = $profileService->firstOrCreateFor($user);

            $entitlements = CustomerEntitlement::where('customer_profile_id', $profile->id)
                ->where('entitlement_type', 'digital_download')
                ->whereNull('revoked_at')
                ->orderByDesc('id')
                ->get();

            foreach ($entitlements as $entitlement) {
                $downloadUrls[$entitlement->id] = URL::temporarySignedRoute(
                    'storefront.digital.download',
                    now()->addMinutes(15),
                    ['entitlementUuid' => $entitlement->uuid]
                );
            }
        }

        return view('digital-delivery::livewire.storefront.digital-downloads-page', [
            'entitlements' => $entitlements,
            'downloadUrls' => $downloadUrls,
        ]);
    }
}
