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
        $tenant = app(ContextManager::class)->getTenant();
        $tenantId = $tenant->getId();
        if ($tenantId === null) {
            throw new \RuntimeException('Tenant context required.');
        }
        $tenantId = (int) $tenantId;

        return view('inventory::livewire.inventory-movement-history', [
            'movements' => InventoryMovement::where('tenant_id', $tenantId)->latest('created_at')->paginate(25),
        ])->layout('layouts.control-center', ['title' => 'Stock Movement History']);
    }
}
