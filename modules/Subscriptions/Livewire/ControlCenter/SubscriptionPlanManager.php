<?php

declare(strict_types=1);

namespace Modules\Subscriptions\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Subscriptions\Models\SubscriptionPlan;
use RuntimeException;

class SubscriptionPlanManager extends Component
{
    public int $productId = 0;

    public string $name = '';

    public string $billingInterval = 'monthly';

    public string $billingIntervalDays = '';

    public string $trialDays = '';

    public function mount(): void
    {
        $this->assertCan('subscriptions.plans.view');
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

    public function createPlan(): void
    {
        $this->assertCan('subscriptions.plans.manage');

        $this->validate([
            'productId' => 'required|integer|min:1',
            'name' => 'required|string|max:255',
            'billingInterval' => 'required|in:monthly,yearly,custom_days',
            'billingIntervalDays' => 'nullable|integer|min:1',
            'trialDays' => 'nullable|integer|min:0',
        ]);

        SubscriptionPlan::create([
            'tenant_id' => $this->tenantId(),
            'product_id' => $this->productId,
            'name' => $this->name,
            'billing_interval' => $this->billingInterval,
            'billing_interval_days' => $this->billingIntervalDays !== '' ? (int) $this->billingIntervalDays : null,
            'trial_days' => $this->trialDays !== '' ? (int) $this->trialDays : null,
        ]);

        $this->reset(['productId', 'name', 'billingIntervalDays', 'trialDays']);
        session()->flash('success', 'Subscription plan created.');
    }

    public function render(): View
    {
        $plans = SubscriptionPlan::where('tenant_id', $this->tenantId())
            ->with('product')
            ->orderByDesc('id')
            ->get();

        return view('subscriptions::livewire.control-center.subscription-plan-manager', [
            'plans' => $plans,
        ]);
    }
}
