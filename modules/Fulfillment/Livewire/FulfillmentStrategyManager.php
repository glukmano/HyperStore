<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class FulfillmentStrategyManager extends Component
{
    public function render(): View
    {
        return view('fulfillment::livewire.strategy-manager');
    }
}
