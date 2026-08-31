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

class InventoryReceivingManager extends Component
{
    public ?int $selectedStockItemId = null;

    public string $quantity = '1.0000';

    public string $reference = '';

    public function receiveStock(InventoryAdjustmentServiceInterface $adjustmentService): void
    {
        $this->validate([
            'selectedStockItemId' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        /** @var StockItem $item */
        $item = StockItem::findOrFail($this->selectedStockItemId);

        $adjustmentService->receive(
            stockItem: $item,
            quantity: Quantity::fromString($this->quantity),
            referenceType: 'manual_receive',
            referenceId: $this->reference
        );

        $this->reset(['quantity', 'reference', 'selectedStockItemId']);
        session()->flash('success', 'Physical stock received successfully.');
    }

    public function render(): View|Factory
    {
        $tenant = app(ContextManager::class)->getTenant();
        $tenantId = $tenant->getId();
        if ($tenantId === null) {
            throw new \RuntimeException('Tenant context required.');
        }
        $tenantId = (int) $tenantId;

        return view('inventory::livewire.inventory-receiving-manager', [
            'stockItems' => StockItem::where('tenant_id', $tenantId)->with(['product', 'inventorySource'])->get(),
        ])->layout('layouts.control-center', ['title' => 'Receive Stock']);
    }
}
