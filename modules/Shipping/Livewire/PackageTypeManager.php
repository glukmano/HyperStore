<?php

declare(strict_types=1);

namespace Modules\Shipping\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Shipping\Models\PackageType;

class PackageTypeManager extends Component
{
    public function render(): View
    {
        $tenantId = app(ContextManager::class)->getTenant()?->getId();
        $types = $tenantId ? PackageType::where('tenant_id', $tenantId)->get() : collect();

        return view('shipping::livewire.package-type-manager', ['packageTypes' => $types]);
    }
}
