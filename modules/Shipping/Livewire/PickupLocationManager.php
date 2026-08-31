<?php

declare(strict_types=1);

namespace Modules\Shipping\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class PickupLocationManager extends Component
{
    public function render(): View
    {
        return view('shipping::livewire.pickup-location-manager');
    }
}
