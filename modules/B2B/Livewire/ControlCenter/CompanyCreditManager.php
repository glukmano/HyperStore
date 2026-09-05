<?php

declare(strict_types=1);

namespace Modules\B2B\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\B2B\Contracts\CompanyCreditServiceInterface;
use Modules\B2B\Enums\CompanyInvoiceStatus;
use Modules\B2B\Models\CompanyCreditAccount;
use Modules\B2B\Models\CompanyCreditEntry;
use Modules\B2B\Models\CompanyInvoice;
use Modules\Order\Models\Order;
use RuntimeException;

class CompanyCreditManager extends Component
{
    public function mount(): void
    {
        $this->assertCan('b2b.companies.view');
    }

    private function assertCan(string $permission): void
    {
        if (! auth()->user()?->can($permission) && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }

    private function tenantId(): int
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId();
        if ($tenantId === null) {
            throw new RuntimeException('Tenant context required.');
        }

        return (int) $tenantId;
    }

    public function markInvoicePaid(int $invoiceId, CompanyCreditServiceInterface $creditService): void
    {
        $this->assertCan('b2b.companies.manage');

        $invoice = CompanyInvoice::where('tenant_id', $this->tenantId())->findOrFail($invoiceId);
        $order = Order::findOrFail($invoice->order_id);

        $creditService->settleReservation($this->tenantId(), (string) $order->uuid);
        $invoice->update(['status' => CompanyInvoiceStatus::Paid, 'paid_at' => now()]);

        session()->flash('success', 'Invoice marked paid; Company credit exposure resolved.');
    }

    public function cancelInvoice(int $invoiceId, CompanyCreditServiceInterface $creditService): void
    {
        $this->assertCan('b2b.companies.manage');

        $invoice = CompanyInvoice::where('tenant_id', $this->tenantId())->findOrFail($invoiceId);
        $order = Order::findOrFail($invoice->order_id);

        $creditService->releaseReservation($this->tenantId(), (string) $order->uuid);
        $invoice->update(['status' => CompanyInvoiceStatus::Cancelled]);

        session()->flash('success', 'Invoice cancelled; Company credit exposure released.');
    }

    public function render(): View
    {
        $tenantId = $this->tenantId();

        $accounts = CompanyCreditAccount::where('tenant_id', $tenantId)->with('company')->get();
        $balances = [];
        foreach ($accounts as $account) {
            $outstanding = (int) CompanyCreditEntry::where('company_credit_account_id', $account->id)->sum('amount_minor');
            $balances[$account->id] = [
                'outstanding' => $outstanding,
                'available' => max(0, $account->approved_limit_minor - $outstanding),
            ];
        }

        $openInvoices = CompanyInvoice::where('tenant_id', $tenantId)
            ->where('status', CompanyInvoiceStatus::Open)
            ->with('company')
            ->orderBy('due_at')
            ->get();

        return view('b2b::livewire.control-center.company-credit-manager', [
            'accounts' => $accounts,
            'balances' => $balances,
            'openInvoices' => $openInvoices,
        ]);
    }
}
