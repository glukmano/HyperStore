<?php

declare(strict_types=1);

namespace Modules\Inventory\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Inventory\Models\InventoryMovement;

class InventoryMovementHistory extends Component
{
    public function render(): View|Factory
    {
        $tenantId = (int) (app(ContextManager::class)->getTenant()->getId() ?? 1);

        return view('inventory::livewire.inventory-movement-history', [
            'movements' => InventoryMovement::where('tenant_id', $tenantId)->latest('created_at')->paginate(25),
        ])->layout('layouts.control-center', ['title' => 'Stock Movement History']);
    }
}
