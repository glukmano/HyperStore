<?php

declare(strict_types=1);

namespace Modules\Affiliate\Livewire\Storefront;

use App\Core\Context\ContextManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Affiliate\Enums\AffiliateStatus;
use Modules\Affiliate\Models\Affiliate;

/**
 * A Customer applies to become an Affiliate using their existing Customer
 * authentication — no second user type/guard (mirrors how a Vendor is
 * layered on User+Vendor rather than a parallel auth system).
 */
class AffiliateApplicationForm extends Component
{
    public string $display_name = '';

    public string $payout_currency = 'USD';

    public function apply(): void
    {
        $user = auth()->user();
        abort_if($user === null, 403);

        $tenantId = app(ContextManager::class)->getTenant()->getId();
        abort_if($tenantId === null, 400, 'Tenant context required.');

        $validated = $this->validate([
            'display_name' => ['required', 'string', 'max:150'],
            'payout_currency' => ['required', 'string', 'size:3'],
        ]);

        $existing = Affiliate::where('tenant_id', $tenantId)->where('user_id', $user->id)->first();
        if ($existing !== null) {
            session()->flash('error', 'You have already applied.');

            return;
        }

        Affiliate::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'display_name' => $validated['display_name'],
            'status' => AffiliateStatus::Pending,
            'payout_currency' => strtoupper((string) $validated['payout_currency']),
            'applied_at' => now(),
        ]);

        session()->flash('success', 'Application submitted — pending review.');
    }

    public function render(): View
    {
        $user = auth()->user();
        $tenantId = app(ContextManager::class)->getTenant()->getId();

        $existing = $user !== null && $tenantId !== null
            ? Affiliate::where('tenant_id', $tenantId)->where('user_id', $user->id)->first()
            : null;

        return view('affiliate::livewire.storefront.affiliate-application-form', [
            'existing' => $existing,
        ]);
    }
}
