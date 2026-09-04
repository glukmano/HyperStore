<?php

declare(strict_types=1);

namespace Modules\Marketplace\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Marketplace\Contracts\VendorOperationalLifecycleServiceInterface;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Models\Vendor;

class VendorDetail extends Component
{
    public int $vendorId;

    public bool $showApproveConfirm = false;

    public bool $showSuspendConfirm = false;

    public bool $showReactivateConfirm = false;

    public bool $showTerminateConfirm = false;

    public function mount(int $vendorId): void
    {
        $tenantId = $this->currentTenantId();

        // Trigger 404 if the vendor does not exist within the current tenant.
        Vendor::where('tenant_id', $tenantId)->findOrFail($vendorId);

        $this->vendorId = $vendorId;
    }

    public function openApproveConfirm(): void
    {
        $this->showApproveConfirm = true;
    }

    public function openSuspendConfirm(): void
    {
        $this->showSuspendConfirm = true;
    }

    public function openReactivateConfirm(): void
    {
        $this->showReactivateConfirm = true;
    }

    public function openTerminateConfirm(): void
    {
        $this->showTerminateConfirm = true;
    }

    public function cancelConfirm(): void
    {
        $this->showApproveConfirm = false;
        $this->showSuspendConfirm = false;
        $this->showReactivateConfirm = false;
        $this->showTerminateConfirm = false;
    }

    public function approve(): void
    {
        if (! auth()->user()?->can('vendors.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = $this->currentTenantId();

        try {
            app(VendorOperationalLifecycleServiceInterface::class)->approveVendor($tenantId, $this->vendorId);
            session()->flash('success', 'Vendor approved successfully.');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->showApproveConfirm = false;
    }

    public function suspend(): void
    {
        if (! auth()->user()?->can('vendors.suspend') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = $this->currentTenantId();

        try {
            app(VendorOperationalLifecycleServiceInterface::class)->suspendVendor($tenantId, $this->vendorId);
            session()->flash('success', 'Vendor suspended successfully.');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->showSuspendConfirm = false;
    }

    public function reactivate(): void
    {
        if (! auth()->user()?->can('vendors.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = $this->currentTenantId();

        try {
            app(VendorOperationalLifecycleServiceInterface::class)->reactivateVendor($tenantId, $this->vendorId);
            session()->flash('success', 'Vendor reactivated successfully.');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->showReactivateConfirm = false;
    }

    public function terminate(): void
    {
        if (! auth()->user()?->can('vendors.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenantId = $this->currentTenantId();

        try {
            app(VendorOperationalLifecycleServiceInterface::class)->transitionStatus(
                $tenantId,
                $this->vendorId,
                VendorOperationalStatus::Terminated
            );
            session()->flash('success', 'Vendor terminated successfully.');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->showTerminateConfirm = false;
    }

    public function render(): View|Factory
    {
        $tenantId = $this->currentTenantId();

        /** @var Vendor $vendor */
        $vendor = Vendor::where('tenant_id', $tenantId)
            ->with(['plan', 'defaultStore'])
            ->findOrFail($this->vendorId);

        return view('marketplace::livewire.vendor-detail', [
            'vendor' => $vendor,
        ])->layout('layouts.control-center', ['title' => 'Vendor: '.$vendor->name]);
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
