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

    public string $type = 'owned';

    public string $timezone = 'UTC';

    public function createWarehouse(): void
    {
        $this->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'size:2'],
        ]);

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        Warehouse::create([
            'tenant_id' => $tenantId,
            'code' => $this->code,
            'name' => $this->name,
            'country_code' => strtoupper($this->country_code),
            'type' => $this->type,
            'timezone' => $this->timezone,
            'status' => 'active',
        ]);

        $this->reset(['code', 'name']);
        session()->flash('success', 'Warehouse created successfully.');
    }

    public function render(): View|Factory
    {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return view('inventory::livewire.warehouse-manager', [
            'warehouses' => Warehouse::where('tenant_id', $tenantId)->get(),
        ])->layout('layouts.control-center', ['title' => 'Warehouses']);
    }
}
