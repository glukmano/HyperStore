<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Dropshipping\Models\PurchaseOrder;

/**
 * Read-only Purchase Order detail screen.
 *
 * `Modules\Dropshipping\Contracts\DropshipOrderOrchestratorInterface` exposes exactly one method
 * (`createPurchaseOrderForFulfillment`) — there is no submit/acknowledge/ship/deliver/cancel status
 * transition on the interface. The concrete `DropshipOrderOrchestrator` service class does carry a
 * `transmitPurchaseOrder()` method, but it is not part of the public contract, so per this task's own
 * "do not invent a transition method" rule it is not wired to any button here. This screen is therefore
 * pure read-only: header, line items, and invoice/reconciliation status are displayed but nothing on
 * this screen mutates PurchaseOrder state.
 */
class PurchaseOrderDetail extends Component
{
    public int $purchaseOrderId;

    public function mount(int $purchaseOrderId): void
    {
        $tenantId = $this->currentTenantId();

        // Trigger 404 if the purchase order does not exist within the current tenant.
        PurchaseOrder::where('tenant_id', $tenantId)->findOrFail($purchaseOrderId);

        $this->purchaseOrderId = $purchaseOrderId;
    }

    public function render(): View|Factory
    {
        if (! auth()->user()?->can('purchase_orders.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = $this->currentTenantId();

        /** @var PurchaseOrder $purchaseOrder */
        $purchaseOrder = PurchaseOrder::where('tenant_id', $tenantId)
            ->with(['supplier', 'lines', 'fulfillment', 'invoices'])
            ->findOrFail($this->purchaseOrderId);

        return view('dropshipping::livewire.purchase-order-detail', [
            'purchaseOrder' => $purchaseOrder,
        ])->layout('layouts.control-center', ['title' => 'Purchase Order: '.$purchaseOrder->po_number]);
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
