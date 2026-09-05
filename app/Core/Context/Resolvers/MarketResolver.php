<?php

declare(strict_types=1);

namespace App\Core\Context\Resolvers;

use App\Core\Context\Contracts\MarketContextInterface;
use App\Core\Context\Contracts\MarketResolverInterface;
use App\Core\Context\Contracts\StoreContextInterface;
use App\Core\Context\DTOs\MarketContext;
use App\Core\Markets\Models\Market;
use App\Core\Routing\DomainAddressingService;
use App\Core\Stores\Models\Store;
use Illuminate\Http\Request;

class MarketResolver implements MarketResolverInterface
{
    public function __construct(
        private readonly ?Request $request = null,
        private readonly ?StoreContextInterface $storeContext = null,
        private readonly ?DomainAddressingService $domainAddressingService = null,
    ) {}

    public function resolve(): MarketContextInterface
    {
        if ($this->request === null) {
            return MarketContext::unresolved();
        }

        // Tier 0 — explicit hostname/domain mapping (Owner Delta §4/§15):
        // a regional Market domain unambiguously identifies its Market,
        // ranking above any query/header override.
        if ($this->domainAddressingService !== null) {
            $hostContext = $this->domainAddressingService->resolveHostContext($this->request->getHost());
            if ($hostContext->market !== null && $hostContext->market->is_active) {
                return MarketContext::from($hostContext->market->id, $hostContext->market->code);
            }
        }

        $code = $this->request->header('X-Market-Code') ?? $this->request->query('market');
        if (is_string($code) && $code !== '') {
            $market = Market::query()->where('code', strtoupper($code))->where('is_active', true)->first();
            if ($market !== null) {
                return MarketContext::from($market->id, $market->code);
            }
        }

        if ($this->storeContext !== null && $this->storeContext->isResolved()) {
            /** @var Store|null $store */
            $store = Store::query()->with('markets')->find($this->storeContext->getId());
            $defaultMarket = $store?->defaultMarket();
            if ($defaultMarket !== null) {
                return MarketContext::from($defaultMarket->id, $defaultMarket->code);
            }

            $firstStoreMarket = $store?->markets()->wherePivot('is_active', true)->first();
            if ($firstStoreMarket !== null) {
                return MarketContext::from($firstStoreMarket->id, $firstStoreMarket->code);
            }
        }

        $globalMarket = Market::query()->where('is_active', true)->first();
        if ($globalMarket !== null) {
            return MarketContext::from($globalMarket->id, $globalMarket->code);
        }

        return MarketContext::unresolved();
    }
}
