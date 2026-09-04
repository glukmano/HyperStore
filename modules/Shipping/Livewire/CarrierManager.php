<?php

declare(strict_types=1);

namespace Modules\Shipping\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Shipping\Models\Carrier;
use RuntimeException;

class CarrierManager extends Component
{
    public string $code = '';

    public string $name = '';

    public string $providerCode = 'manual';

    public function createCarrier(): void
    {
        $tenant = app(ContextManager::class)->getTenant();
        if ($tenant === null) {
            throw new RuntimeException('Tenant context required.');
        }

        if (! auth()->user()?->can('shipping.carriers.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $this->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'providerCode' => ['required', 'string'],
        ]);

        Carrier::create([
            'tenant_id' => $tenant->getId(),
            'code' => $this->code,
            'name' => $this->name,
            'provider_code' => $this->providerCode,
            'status' => 'active',
        ]);

        $this->reset(['code', 'name']);
    }

    public function render(): View
    {
        $tenantId = app(ContextManager::class)->getTenant()?->getId();
        $carriers = $tenantId ? Carrier::where('tenant_id', $tenantId)->with('services')->get() : collect();

        return view('shipping::livewire.carrier-manager', ['carriers' => $carriers])
            ->layout('layouts.control-center', ['title' => 'Carriers']);
    }
}
