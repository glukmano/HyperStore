<?php

declare(strict_types=1);

namespace Modules\Affiliate\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Affiliate\Models\Affiliate;
use Modules\Affiliate\Models\AffiliateCommissionRule;
use RuntimeException;

class AffiliateCommissionRuleManager extends Component
{
    public ?int $affiliate_id = null;

    public ?int $category_id = null;

    public int $rate_basis_points = 0;

    public int $fixed_fee_minor = 0;

    public string $currency = 'USD';

    public function mount(): void
    {
        $this->assertCan('affiliates.view');
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

    public function createRule(): void
    {
        $this->assertCan('affiliates.manage');

        $validated = $this->validate([
            'affiliate_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'rate_basis_points' => ['required', 'integer', 'min:0', 'max:10000'],
            'fixed_fee_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        AffiliateCommissionRule::create([
            'tenant_id' => $this->tenantId(),
            'affiliate_id' => $validated['affiliate_id'],
            'category_id' => $validated['category_id'],
            'rate_basis_points' => $validated['rate_basis_points'],
            'fixed_fee_minor' => $validated['fixed_fee_minor'],
            'currency' => strtoupper((string) $validated['currency']),
            'is_active' => true,
        ]);

        session()->flash('success', 'Commission rule created.');
        $this->reset(['affiliate_id', 'category_id', 'rate_basis_points', 'fixed_fee_minor']);
    }

    public function deactivate(int $ruleId): void
    {
        $this->assertCan('affiliates.manage');

        $rule = AffiliateCommissionRule::where('tenant_id', $this->tenantId())->findOrFail($ruleId);
        $rule->is_active = false;
        $rule->save();

        session()->flash('success', 'Commission rule deactivated.');
    }

    public function render(): View
    {
        $tenantId = $this->tenantId();

        return view('affiliate::livewire.control-center.affiliate-commission-rule-manager', [
            'rules' => AffiliateCommissionRule::where('tenant_id', $tenantId)->orderByDesc('id')->get(),
            'affiliates' => Affiliate::where('tenant_id', $tenantId)->orderBy('display_name')->get(),
        ]);
    }
}
