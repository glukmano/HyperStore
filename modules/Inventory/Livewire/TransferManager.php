<?php

declare(strict_types=1);

namespace Modules\Inventory\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Catalog\Models\Product;
use Modules\Inventory\Contracts\InventoryTransferServiceInterface;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\InventoryTransfer;

class TransferManager extends Component
{
    use WithPagination;

    public string $transfer_number = '';

    public ?int $source_inventory_source_id = null;

    public ?int $destination_inventory_source_id = null;

    /** @var array<int, array{product_id: ?int, requested_quantity: string}> */
    public array $items = [
        ['product_id' => null, 'requested_quantity' => ''],
    ];

    public ?int $confirmCancelTransferId = null;

    public function addItemLine(): void
    {
        $this->items[] = ['product_id' => null, 'requested_quantity' => ''];
    }

    public function removeItemLine(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);

        if ($this->items === []) {
            $this->items[] = ['product_id' => null, 'requested_quantity' => ''];
        }
    }

    public function createTransfer(InventoryTransferServiceInterface $transferService): void
    {
        if (! auth()->user()?->can('inventory.transfer.create') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $this->validate([
            'transfer_number' => ['required', 'string', 'max:100'],
            'source_inventory_source_id' => ['required', 'integer', 'different:destination_inventory_source_id'],
            'destination_inventory_source_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.requested_quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $tenantId = $this->currentTenantId();

        $lines = array_values(array_map(
            fn (array $line): array => [
                'product_id' => (int) $line['product_id'],
                'requested_quantity' => (string) $line['requested_quantity'],
            ],
            $this->items
        ));

        $transferService->create(
            tenantId: $tenantId,
            sourceInventorySourceId: (int) $this->source_inventory_source_id,
            destinationInventorySourceId: (int) $this->destination_inventory_source_id,
            transferNumber: $this->transfer_number,
            items: $lines,
        );

        $this->reset(['transfer_number', 'source_inventory_source_id', 'destination_inventory_source_id', 'items']);
        $this->items = [['product_id' => null, 'requested_quantity' => '']];

        session()->flash('success', 'Transfer created successfully.');
    }

    public function dispatchTransfer(int $transferId, InventoryTransferServiceInterface $transferService): void
    {
        if (! auth()->user()?->can('inventory.transfer.dispatch') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $transfer = InventoryTransfer::where('tenant_id', $this->currentTenantId())->findOrFail($transferId);

        $transferService->dispatch($transfer);

        session()->flash('success', 'Transfer dispatched successfully.');
    }

    public function receiveTransfer(int $transferId, InventoryTransferServiceInterface $transferService): void
    {
        if (! auth()->user()?->can('inventory.transfer.receive') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $transfer = InventoryTransfer::where('tenant_id', $this->currentTenantId())->findOrFail($transferId);

        $transferService->receive($transfer);

        session()->flash('success', 'Transfer received successfully.');
    }

    public function confirmCancel(int $transferId): void
    {
        $this->confirmCancelTransferId = $transferId;
    }

    public function cancelCancelConfirmation(): void
    {
        $this->confirmCancelTransferId = null;
    }

    public function cancelTransfer(InventoryTransferServiceInterface $transferService): void
    {
        if (! auth()->user()?->can('inventory.transfer.cancel') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        if ($this->confirmCancelTransferId === null) {
            return;
        }

        $transfer = InventoryTransfer::where('tenant_id', $this->currentTenantId())->findOrFail($this->confirmCancelTransferId);

        $transferService->cancel($transfer);

        $this->confirmCancelTransferId = null;
        session()->flash('success', 'Transfer cancelled successfully.');
    }

    public function render(): View|Factory
    {
        $tenantId = $this->currentTenantId();

        return view('inventory::livewire.transfer-manager', [
            'transfers' => InventoryTransfer::where('tenant_id', $tenantId)
                ->with(['sourceInventorySource.warehouse', 'destinationInventorySource.warehouse', 'items'])
                ->latest()
                ->paginate(25),
            'inventorySources' => InventorySource::where('tenant_id', $tenantId)->where('status', 'active')->with('warehouse')->get(),
            'products' => Product::where('tenant_id', $tenantId)->where('status', 'active')->with('translations')->get(),
        ])->layout('layouts.control-center', ['title' => 'Warehouse Transfers']);
    }

    private function currentTenantId(): int
    {
        $tenant = app(ContextManager::class)->getTenant();
        $tenantId = $tenant->getId();
        if ($tenantId === null) {
            throw new \RuntimeException('Tenant context required.');
        }

        return (int) $tenantId;
    }
}
