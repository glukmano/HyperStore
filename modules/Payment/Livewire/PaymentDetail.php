<?php

declare(strict_types=1);

namespace Modules\Payment\Livewire;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Payment\Exceptions\PaymentNotFoundException;
use Modules\Payment\Models\Payment;
use RuntimeException;

/**
 * Read-only Control Center Payment detail: shows a single Payment plus its full
 * transaction history. Field exposure mirrors Modules\Payment\Http\Resources\PaymentResource
 * and PaymentTransactionResource exactly — no field is rendered here that those
 * Resources do not already expose (provider_response_code and provider_idempotency_key
 * are deliberately withheld, matching the Resources). View-only: no capture/refund/void.
 */
class PaymentDetail extends Component
{
    public string $uuid;

    public function mount(string $uuid): void
    {
        if (! auth()->user()?->can('payments.view') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }

        $this->uuid = $uuid;
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

        /** @var Payment|null $payment */
        $payment = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('uuid', $this->uuid)
            ->with(['order', 'transactions'])
            ->first();

        if ($payment === null) {
            throw PaymentNotFoundException::forUuid($this->uuid);
        }

        return view('payment::livewire.payment-detail', [
            'payment' => $payment,
        ])->layout('layouts.control-center', ['title' => 'Payment '.$payment->uuid]);
    }
}
