<?php

declare(strict_types=1);

namespace Modules\Inventory\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Models\Warehouse;

class TransferManager extends Component
{
    public function render(): View|Factory
    {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return view('inventory::livewire.transfer-manager', [
            'transfers' => InventoryTransfer::where('tenant_id', $tenantId)->with(['sourceWarehouse', 'destinationWarehouse', 'items'])->latest()->paginate(25),
            'warehouses' => Warehouse::where('tenant_id', $tenantId)->get(),
        ])->layout('layouts.control-center', ['title' => 'Warehouse Transfers']);
    }
}
