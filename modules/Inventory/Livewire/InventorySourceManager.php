<?php

declare(strict_types=1);

namespace Modules\Inventory\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\Warehouse;

class InventorySourceManager extends Component
{
    public string $code = '';

    public string $name = '';

    public string $source_type = 'warehouse';

    public ?int $warehouse_id = null;

    public int $priority = 0;

    public function createSource(): void
    {
        $this->validate([
            'code' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'source_type' => ['required', 'string'],
        ]);

        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        InventorySource::create([
            'tenant_id' => $tenantId,
            'warehouse_id' => $this->warehouse_id,
            'source_type' => $this->source_type,
            'code' => $this->code,
            'name' => $this->name,
            'priority' => $this->priority,
            'status' => 'active',
        ]);

        $this->reset(['code', 'name', 'warehouse_id', 'priority']);
        session()->flash('success', 'Inventory Source created successfully.');
    }

    public function render(): View|Factory
    {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return view('inventory::livewire.inventory-source-manager', [
            'sources' => InventorySource::where('tenant_id', $tenantId)->with('warehouse')->get(),
            'warehouses' => Warehouse::where('tenant_id', $tenantId)->get(),
        ])->layout('layouts.control-center', ['title' => 'Inventory Sources']);
    }
}
