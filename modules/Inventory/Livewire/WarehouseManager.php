<?php

declare(strict_types=1);

namespace Modules\Inventory\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Inventory\Models\Warehouse;

class WarehouseManager extends Component
{
    public string $code = '';

    public string $name = '';

    public string $country_code = 'CH';

    public ?string $type = null;

    public string $ownership_type = 'platform';

    public string $timezone = 'UTC';

    public function createWarehouse(): void
    {
        if (! auth()->user()?->can('warehouses.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $this->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'size:2'],
            'type' => ['nullable', 'string', 'in:fulfillment_center,retail_store,distribution_center,hub'],
            'ownership_type' => ['required', 'string', 'in:platform,vendor,3pl'],
        ]);

        $tenant = app(ContextManager::class)->getTenant();
        $tenantId = $tenant->getId();
        if ($tenantId === null) {
            throw new \RuntimeException('Tenant context required.');
        }
        $tenantId = (int) $tenantId;

        Warehouse::create([
            'tenant_id' => $tenantId,
            'code' => $this->code,
            'name' => $this->name,
            'country_code' => strtoupper($this->country_code),
            'type' => $this->type,
            'ownership_type' => $this->ownership_type,
            'timezone' => $this->timezone,
            'status' => 'active',
        ]);

        $this->reset(['code', 'name']);
        session()->flash('success', 'Warehouse created successfully.');
    }

    public function render(): View|Factory
    {
        $tenant = app(ContextManager::class)->getTenant();
        $tenantId = $tenant->getId();
        if ($tenantId === null) {
            throw new \RuntimeException('Tenant context required.');
        }
        $tenantId = (int) $tenantId;

        return view('inventory::livewire.warehouse-manager', [
            'warehouses' => Warehouse::where('tenant_id', $tenantId)->get(),
        ])->layout('layouts.control-center', ['title' => 'Warehouses']);
    }
}
