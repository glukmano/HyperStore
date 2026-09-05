<?php

declare(strict_types=1);

namespace App\Livewire\ControlCenter;

use App\Core\Context\ContextManager;
use App\Core\Markets\Models\MarketDomain;
use App\Core\Markets\Models\StoreMarket;
use App\Core\Routing\Exceptions\HostnameAlreadyClaimedException;
use App\Core\Stores\Models\StoreDomain;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * One screen for regional (Market) domains — Store domains and Vendor
 * domains stay in their own existing bounded contexts (Owner Delta §4/§19
 * scoped this screen to the new Market-domain concept only), but both are
 * listed here read-only so an operator has one place to see every
 * hostname claim. Owner Delta §6: new domains never default to verified.
 */
class DomainManager extends Component
{
    public string $store_market_id = '';

    public string $domain = '';

    public function createMarketDomain(): void
    {
        $this->authorizeManage();

        $validated = $this->validate([
            'store_market_id' => ['required', 'integer'],
            'domain' => ['required', 'string', 'max:255'],
        ]);

        try {
            MarketDomain::create([
                'store_market_id' => (int) $validated['store_market_id'],
                'domain' => $validated['domain'],
                'is_verified' => false,
                'canonical' => false,
            ]);
        } catch (HostnameAlreadyClaimedException $e) {
            $this->addError('domain', $e->getMessage());

            return;
        }

        $this->reset(['domain']);
        session()->flash('success', 'Domain created — verify it before it can resolve storefront traffic.');
    }

    public function verifyDomain(int $marketDomainId): void
    {
        $this->authorizeManage();

        MarketDomain::where('id', $marketDomainId)->update(['is_verified' => true]);
        session()->flash('success', 'Domain verified.');
    }

    public function setCanonical(int $marketDomainId): void
    {
        $this->authorizeManage();

        $marketDomain = MarketDomain::find($marketDomainId);
        if ($marketDomain === null || ! $marketDomain->is_verified) {
            session()->flash('error', 'Only a verified domain can become canonical.');

            return;
        }

        MarketDomain::where('store_market_id', $marketDomain->store_market_id)->update(['canonical' => false]);
        $marketDomain->update(['canonical' => true]);
        session()->flash('success', 'Canonical domain updated.');
    }

    private function authorizeManage(): void
    {
        if (! auth()->user()?->can('domains.manage') && ! auth()->user()?->is_super_admin) {
            abort(403, 'Permission denied.');
        }
    }

    public function render(): View
    {
        $tenantId = app(ContextManager::class)->getTenant()->getId();

        $storeMarkets = StoreMarket::query()
            ->whereHas('store', fn ($q) => $tenantId !== null ? $q->where('tenant_id', $tenantId) : $q)
            ->where('is_active', true)
            ->with(['store', 'market'])
            ->get();

        $marketDomains = MarketDomain::query()
            ->whereIn('store_market_id', $storeMarkets->pluck('id'))
            ->with(['storeMarket.store', 'storeMarket.market'])
            ->orderByDesc('id')
            ->get();

        $storeDomains = StoreDomain::query()
            ->whereIn('store_id', $storeMarkets->pluck('store_id')->unique())
            ->with('store')
            ->orderByDesc('id')
            ->get();

        return view('livewire.control-center.domain-manager', [
            'storeMarkets' => $storeMarkets,
            'marketDomains' => $marketDomains,
            'storeDomains' => $storeDomains,
        ])->layout('layouts.control-center', ['title' => 'Domains']);
    }
}
