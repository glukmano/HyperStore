<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Dropshipping\Enums\PurchaseOrderStatus;
use Modules\Dropshipping\Models\PurchaseOrder;

class PurchaseOrderList extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View|Factory
    {
        if (! auth()->user()?->can('purchase_orders.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = $this->currentTenantId();

        // Every PurchaseOrder.type in this codebase is 'dropship' (Phase-14 §3 exclusion) — there is
        // no warehouse-bound receiving PO type to filter out, so no additional `type` scoping is needed.
        $purchaseOrders = PurchaseOrder::where('tenant_id', $tenantId)
            ->with('supplier')
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('dropshipping::livewire.purchase-order-list', [
            'purchaseOrders' => $purchaseOrders,
            'statuses' => array_map(fn (PurchaseOrderStatus $status) => $status->value, PurchaseOrderStatus::cases()),
        ])->layout('layouts.control-center', ['title' => 'Purchase Orders']);
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
