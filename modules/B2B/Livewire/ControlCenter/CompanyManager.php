<?php

declare(strict_types=1);

namespace Modules\B2B\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\B2B\Enums\CompanyStatus;
use Modules\B2B\Models\Company;
use Modules\B2B\Models\CompanyCreditAccount;
use Modules\Pricing\Models\CustomerGroup;
use RuntimeException;

class CompanyManager extends Component
{
    public string $name = '';

    public string $taxId = '';

    public ?int $customerGroupId = null;

    public ?int $paymentTermsDays = null;

    public string $creditLimitCurrency = '';

    public ?int $creditLimitMinor = null;

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

    public function createCompany(): void
    {
        $this->assertCan('b2b.companies.manage');

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $tenantId = $this->tenantId();

        $company = Company::create([
            'tenant_id' => $tenantId,
            'name' => $this->name,
            'tax_id' => $this->taxId !== '' ? $this->taxId : null,
            'status' => CompanyStatus::Pending,
            'customer_group_id' => $this->customerGroupId,
            'payment_terms_days' => $this->paymentTermsDays,
            'credit_limit_currency' => $this->creditLimitCurrency !== '' ? strtoupper($this->creditLimitCurrency) : null,
            'credit_limit_minor' => $this->creditLimitMinor,
        ]);

        if ($this->creditLimitMinor !== null && $this->creditLimitCurrency !== '') {
            CompanyCreditAccount::updateOrCreate(
                ['tenant_id' => $tenantId, 'company_id' => $company->id, 'currency' => strtoupper($this->creditLimitCurrency)],
                ['approved_limit_minor' => $this->creditLimitMinor]
            );
        }

        $this->reset(['name', 'taxId', 'customerGroupId', 'paymentTermsDays', 'creditLimitCurrency', 'creditLimitMinor']);
        session()->flash('success', 'Company created.');
    }

    public function approve(int $companyId): void
    {
        $this->assertCan('b2b.companies.manage');

        $company = Company::where('tenant_id', $this->tenantId())->findOrFail($companyId);
        $company->update(['status' => CompanyStatus::Active]);
        session()->flash('success', 'Company approved.');
    }

    public function suspend(int $companyId): void
    {
        $this->assertCan('b2b.companies.manage');

        $company = Company::where('tenant_id', $this->tenantId())->findOrFail($companyId);
        $company->update(['status' => CompanyStatus::Suspended]);
        session()->flash('success', 'Company suspended.');
    }

    public function render(): View
    {
        $companies = Company::where('tenant_id', $this->tenantId())->orderByDesc('id')->get();
        $customerGroups = CustomerGroup::where('tenant_id', $this->tenantId())->where('is_active', true)->get();

        return view('b2b::livewire.control-center.company-manager', [
            'companies' => $companies,
            'customerGroups' => $customerGroups,
        ])->layout('layouts.control-center', ['title' => 'B2B Companies']);
    }
}
