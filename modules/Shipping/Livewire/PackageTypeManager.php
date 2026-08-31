<?php

declare(strict_types=1);

namespace Modules\Shipping\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class PackageTypeManager extends Component
{
    public function render(): View
    {
        return view('shipping::livewire.package-type-manager');
    }
}
