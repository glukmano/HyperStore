<?php

declare(strict_types=1);

namespace Modules\Shipping\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Shipping\Models\ShippingClass;

class ShippingClassManager extends Component
{
    public function render(): View
    {
        $tenantId = app(ContextManager::class)->getTenant()?->getId();
        $classes = $tenantId ? ShippingClass::where('tenant_id', $tenantId)->get() : collect();

        return view('shipping::livewire.shipping-class-manager', ['classes' => $classes]);
    }
}
