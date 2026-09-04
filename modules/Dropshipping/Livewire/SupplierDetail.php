<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Dropshipping\Models\Supplier;

class SupplierDetail extends Component
{
    public int $supplierId;

    public function mount(int $supplierId): void
    {
        $tenantId = $this->currentTenantId();

        // Trigger 404 if the supplier does not exist within the current tenant.
        Supplier::where('tenant_id', $tenantId)->findOrFail($supplierId);

        $this->supplierId = $supplierId;
    }

    public function render(): View|Factory
    {
        if (! auth()->user()?->can('suppliers.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = $this->currentTenantId();

        /** @var Supplier $supplier */
        $supplier = Supplier::where('tenant_id', $tenantId)
            ->with(['vendor'])
            ->findOrFail($this->supplierId);

        $recentPurchaseOrders = $supplier->purchaseOrders()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $locations = $supplier->locations()
            ->orderBy('name')
            ->limit(20)
            ->get();

        $offers = $supplier->offers()
            ->with(['supplierProductVariant', 'supplierLocation'])
            ->orderByDesc('synced_at')
            ->limit(20)
            ->get();

        return view('dropshipping::livewire.supplier-detail', [
            'supplier' => $supplier,
            'recentPurchaseOrders' => $recentPurchaseOrders,
            'locations' => $locations,
            'offers' => $offers,
        ])->layout('layouts.control-center', ['title' => 'Supplier: '.$supplier->name]);
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
