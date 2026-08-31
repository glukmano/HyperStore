<?php

declare(strict_types=1);

namespace Modules\Shipping\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Shipping\Models\ShippingZone;
use RuntimeException;

class ShippingZoneManager extends Component
{
    public string $code = '';

    public string $name = '';

    public int $priority = 0;

    public function createZone(): void
    {
        $tenant = app(ContextManager::class)->getTenant();
        if ($tenant === null) {
            throw new RuntimeException('Tenant context required.');
        }

        if (! auth()->user()?->can('shipping.zones.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $this->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'integer'],
        ]);

        ShippingZone::create([
            'tenant_id' => $tenant->getId(),
            'code' => $this->code,
            'name' => $this->name,
            'priority' => $this->priority,
            'status' => 'active',
        ]);

        $this->reset(['code', 'name', 'priority']);
    }

    public function render(): View
    {
        $tenantId = app(ContextManager::class)->getTenant()?->getId();
        $zones = $tenantId ? ShippingZone::where('tenant_id', $tenantId)->with('rules')->get() : collect();

        return view('shipping::livewire.shipping-zone-manager', ['zones' => $zones]);
    }
}
