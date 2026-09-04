<?php

declare(strict_types=1);

namespace Modules\Order\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Inventory\Models\InventorySource;
use Modules\Order\Contracts\ReturnPhysicalDispositionServiceInterface;
use Modules\Order\Models\ReturnItem;

class ReturnManager extends Component
{
    use WithPagination;

    public ?int $dispositionReturnItemId = null;

    public ?int $dispositionSellerReturnId = null;

    public ?int $dispositionOrderItemId = null;

    public string $quantityReceived = '';

    public string $condition = '';

    public string $restockAction = 'restock';

    public ?int $destinationInventorySourceId = null;

    /**
     * @return array<int, string>
     */
    public function restockActions(): array
    {
        return ['restock', 'quarantine', 'discard', 'return_to_supplier'];
    }

    public function openDisposition(int $returnItemId): void
    {
        if (! auth()->user()?->can('returns.restock') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = $this->requireTenantId();

        $item = ReturnItem::where('tenant_id', $tenantId)->findOrFail($returnItemId);

        $this->dispositionReturnItemId = $item->id;
        $this->dispositionSellerReturnId = $item->seller_return_id;
        $this->dispositionOrderItemId = $item->order_item_id;
        $this->quantityReceived = (string) $item->quantity_approved;
        $this->condition = (string) ($item->condition ?? '');
        $this->restockAction = $item->restock_action !== '' ? $item->restock_action : 'restock';
        $this->destinationInventorySourceId = $item->destination_inventory_source_id;
    }

    public function closeDisposition(): void
    {
        $this->reset([
            'dispositionReturnItemId',
            'dispositionSellerReturnId',
            'dispositionOrderItemId',
            'quantityReceived',
            'condition',
            'restockAction',
            'destinationInventorySourceId',
        ]);
    }

    public function confirmDisposition(): void
    {
        if (! auth()->user()?->can('returns.restock') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = $this->requireTenantId();

        $this->validate([
            'dispositionSellerReturnId' => ['required', 'integer'],
            'dispositionOrderItemId' => ['required', 'integer'],
            'quantityReceived' => ['required', 'string'],
            'condition' => ['required', 'string', 'max:100'],
            'restockAction' => ['required', 'string', 'in:restock,quarantine,discard,return_to_supplier'],
            'destinationInventorySourceId' => [$this->restockAction === 'restock' ? 'required' : 'nullable', 'integer'],
        ]);

        app(ReturnPhysicalDispositionServiceInterface::class)->confirmPhysicalDisposition(
            tenantId: $tenantId,
            sellerReturnId: (int) $this->dispositionSellerReturnId,
            orderItemId: (int) $this->dispositionOrderItemId,
            quantityReceived: $this->quantityReceived,
            condition: $this->condition,
            restockAction: $this->restockAction,
            destinationInventorySourceId: $this->destinationInventorySourceId,
        );

        $this->closeDisposition();
        session()->flash('success', 'Return disposition confirmed.');
    }

    private function requireTenantId(): int
    {
        $tenant = app(ContextManager::class)->getTenant();
        $tenantId = $tenant->getId();
        if ($tenantId === null) {
            throw new \RuntimeException('Tenant context required.');
        }

        return (int) $tenantId;
    }

    public function render(): View|Factory
    {
        if (! auth()->user()?->can('returns.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = $this->requireTenantId();

        $returnItems = ReturnItem::query()
            ->where('tenant_id', $tenantId)
            ->with(['sellerReturn', 'orderItem'])
            ->orderByDesc('created_at')
            ->paginate(15);

        $inventorySources = InventorySource::where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name']);

        return view('order::livewire.return-manager', [
            'returnItems' => $returnItems,
            'inventorySources' => $inventorySources,
            'restockActions' => $this->restockActions(),
        ])->layout('layouts.control-center', ['title' => 'Returns / RMA']);
    }
}
