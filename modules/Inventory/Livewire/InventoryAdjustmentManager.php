<?php

declare(strict_types=1);

namespace Modules\Inventory\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Inventory\Contracts\InventoryAdjustmentServiceInterface;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\ValueObjects\Quantity;

class InventoryAdjustmentManager extends Component
{
    public ?int $selectedStockItemId = null;

    public string $delta = '0.0000';

    public string $movementType = 'correction';

    public string $reason = '';

    public function applyAdjustment(InventoryAdjustmentServiceInterface $adjustmentService): void
    {
        if (! auth()->user()?->can('inventory.adjust') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $this->validate([
            'selectedStockItemId' => ['required', 'integer'],
            'delta' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        /** @var StockItem $item */
        $item = StockItem::findOrFail($this->selectedStockItemId);

        $adjustmentService->adjust(
            stockItem: $item,
            delta: Quantity::fromString($this->delta),
            movementType: $this->movementType,
            reason: $this->reason
        );

        $this->reset(['delta', 'reason', 'selectedStockItemId']);
        session()->flash('success', 'Inventory adjustment applied.');
    }

    public function render(): View|Factory
    {
        $tenant = app(ContextManager::class)->getTenant();
        $tenantId = $tenant->getId();
        if ($tenantId === null) {
            throw new \RuntimeException('Tenant context required.');
        }
        $tenantId = (int) $tenantId;

        return view('inventory::livewire.inventory-adjustment-manager', [
            'stockItems' => StockItem::where('tenant_id', $tenantId)->with(['product.translations', 'inventorySource'])->get(),
        ])->layout('layouts.control-center', ['title' => 'Stock Adjustments']);
    }
}
