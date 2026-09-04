<?php

declare(strict_types=1);

namespace Modules\Payment\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Models\Payment;
use RuntimeException;

/**
 * Read-only Control Center Payments list. Mirrors Modules\Order\Livewire\OrderList's
 * render()-gated, tenant-scoped inline-resolution pattern. No mutating actions are
 * exposed here — capture/refund/void remain owned by the existing JSON
 * Modules\Payment\Http\Controllers\AdminPaymentController.
 */
class PaymentList extends Component
{
    use WithPagination;

    public string $statusFilter = '';

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View|Factory
    {
        if (! auth()->user()?->can('payments.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $tenant = app(ContextManager::class)->getTenant();
        $tenantId = $tenant->getId();
        if ($tenantId === null) {
            throw new RuntimeException('Tenant context required.');
        }
        $tenantId = (int) $tenantId;

        $payments = Payment::query()
            ->where('tenant_id', $tenantId)
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->with('order')
            ->orderByDesc('created_at')
            ->paginate(15);

        return view('payment::livewire.payment-list', [
            'payments' => $payments,
            'statuses' => array_map(fn (PaymentStatus $status) => $status->value, PaymentStatus::cases()),
        ])->layout('layouts.control-center', ['title' => 'Payments']);
    }
}
