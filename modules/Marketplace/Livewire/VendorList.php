<?php

declare(strict_types=1);

namespace Modules\Marketplace\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Marketplace\Models\Vendor;

class VendorList extends Component
{
    use WithPagination;

    public function render(): View|Factory
    {
        $tenant = app(ContextManager::class)->getTenant();
        $tenantId = $tenant->getId();
        if ($tenantId === null) {
            throw new \RuntimeException('Tenant context required.');
        }
        $tenantId = (int) $tenantId;

        $vendors = Vendor::where('tenant_id', $tenantId)
            ->with('plan')
            ->orderBy('name')
            ->paginate(15);

        return view('marketplace::livewire.vendor-list', [
            'vendors' => $vendors,
        ])->layout('layouts.control-center', ['title' => 'Vendors']);
    }
}
