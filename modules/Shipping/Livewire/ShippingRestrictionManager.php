<?php

declare(strict_types=1);

namespace Modules\Shipping\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Shipping\Models\ShippingRestriction;

class ShippingRestrictionManager extends Component
{
    public function render(): View
    {
        $tenantId = app(ContextManager::class)->getTenant()?->getId();
        $restrictions = $tenantId ? ShippingRestriction::where('tenant_id', $tenantId)->get() : collect();

        return view('shipping::livewire.shipping-restriction-manager', ['restrictions' => $restrictions])
            ->layout('layouts.control-center', ['title' => 'Shipping Restrictions']);
    }
}
