<?php

declare(strict_types=1);

namespace Modules\Shipping\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Shipping\Models\PickupLocation;

class PickupLocationManager extends Component
{
    public function render(): View
    {
        $tenantId = app(ContextManager::class)->getTenant()?->getId();
        $locations = $tenantId ? PickupLocation::where('tenant_id', $tenantId)->get() : collect();

        return view('shipping::livewire.pickup-location-manager', ['locations' => $locations])
            ->layout('layouts.control-center', ['title' => 'Pickup Locations']);
    }
}
