<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Dropshipping\Models\Supplier;

class SupplierList extends Component
{
    use WithPagination;

    public function render(): View|Factory
    {
        if (! auth()->user()?->can('suppliers.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = $this->currentTenantId();

        // Supplier.scope_type ('platform' | 'tenant' | 'private_vendor') distinguishes ownership
        // origin but every Supplier row (platform-provisioned or not) is always persisted with a
        // tenant_id in this codebase (see tests/Feature/Dropshipping/SupplierRoutingAndProcurementTest.php
        // beforeEach, which sets tenant_id on its platform-scoped fixture supplier). BelongsToTenant's
        // global TenantScope already restricts every query to the current tenant; the explicit
        // where() below mirrors the established VendorList/OrderList convention of also being explicit
        // at the query site.
        $suppliers = Supplier::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->paginate(15);

        return view('dropshipping::livewire.supplier-list', [
            'suppliers' => $suppliers,
        ])->layout('layouts.control-center', ['title' => 'Suppliers']);
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
