<?php

declare(strict_types=1);

namespace Modules\Inventory\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Inventory\Models\StockItem;

class StockItemManager extends Component
{
    public function render(): View|Factory
    {
        $tenant = app(ContextManager::class)->getTenant();
        $tenantId = $tenant->getId();
        if ($tenantId === null) {
            throw new \RuntimeException('Tenant context required.');
        }
        $tenantId = (int) $tenantId;

        return view('inventory::livewire.stock-item-manager', [
            'stockItems' => StockItem::where('tenant_id', $tenantId)->with(['product', 'productVariant', 'inventorySource'])->paginate(25),
        ])->layout('layouts.control-center', ['title' => 'Stock Items']);
    }
}
