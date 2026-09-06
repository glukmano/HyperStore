<?php

declare(strict_types=1);

namespace Modules\Subscriptions\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Subscriptions\Models\Subscription;
use Modules\Subscriptions\Services\SubscriptionService;
use RuntimeException;

class SubscriptionManager extends Component
{
    public function mount(): void
    {
        $this->assertCan('subscriptions.manage.view');
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

    public function cancelNow(int $subscriptionId, SubscriptionService $service): void
    {
        $this->assertCan('subscriptions.manage.manage');

        $subscription = Subscription::where('tenant_id', $this->tenantId())->findOrFail($subscriptionId);
        $service->cancelNow($subscription);
        session()->flash('success', 'Subscription cancelled.');
    }

    public function reactivate(int $subscriptionId, SubscriptionService $service): void
    {
        $this->assertCan('subscriptions.manage.manage');

        $subscription = Subscription::where('tenant_id', $this->tenantId())->findOrFail($subscriptionId);
        $service->reactivate($subscription);
        session()->flash('success', 'Subscription reactivated.');
    }

    public function render(): View
    {
        $subscriptions = Subscription::where('tenant_id', $this->tenantId())
            ->with(['customerProfile.user', 'plan', 'renewalAttempts'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return view('subscriptions::livewire.control-center.subscription-manager', [
            'subscriptions' => $subscriptions,
        ]);
    }
}
