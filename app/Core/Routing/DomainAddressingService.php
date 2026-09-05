<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Markets\Models\MarketDomain;
use App\Core\Routing\DTOs\ResolvedHostContext;
use App\Core\Stores\Models\Store;
use App\Core\Stores\Models\StoreDomain;

class DomainAddressingService
{
    public const array RESERVED_SLUGS = [
        'admin',
        'api',
        'app',
        'auth',
        'billing',
        'cdn',
        'control-center',
        'dashboard',
        'docs',
        'graphql',
        'health',
        'mcp',
        'oauth',
        'pos',
        'root',
        'static',
        'up',
        'webhook',
        'cart',
        'checkout',
    ];

    public function isReservedSlug(string $slug): bool
    {
        return in_array(strtolower(trim($slug)), self::RESERVED_SLUGS, true);
    }

    public function findStoreByHost(string $host): ?Store
    {
        return $this->resolveHostContext($host)->store;
    }

    /**
     * Phase-18 Owner Delta §4: a Market may be attached to multiple Stores,
     * so a hostname must resolve the Store+Market PAIR, never a Market
     * alone. Tier 1 checks the regional Market-domain table (verified
     * only, Owner Delta §6); tier 2 falls back to the existing
     * Store-only domain/subdomain resolution.
     */
    public function resolveHostContext(string $host): ResolvedHostContext
    {
        $normalizedHost = HostnameNormalizer::normalize($host);

        $marketDomain = MarketDomain::query()
            ->where('domain', $normalizedHost)
            ->where('is_verified', true)
            ->with(['storeMarket.store', 'storeMarket.market'])
            ->first();

        if (
            $marketDomain !== null
            && $marketDomain->storeMarket !== null
            && $marketDomain->storeMarket->is_active
            && $marketDomain->storeMarket->store !== null
            && $marketDomain->storeMarket->store->isActive()
        ) {
            return new ResolvedHostContext($marketDomain->storeMarket->store, $marketDomain->storeMarket->market);
        }

        $domainRecord = StoreDomain::query()
            ->where('domain', $normalizedHost)
            ->where('is_verified', true)
            ->with('store')
            ->first();

        if ($domainRecord !== null && $domainRecord->store !== null && $domainRecord->store->isActive()) {
            return new ResolvedHostContext($domainRecord->store, null);
        }

        $subdomain = $this->extractSubdomain($normalizedHost);
        if ($subdomain !== null && ! $this->isReservedSlug($subdomain)) {
            $store = Store::query()
                ->where('slug', $subdomain)
                ->where('status', 'active')
                ->first();

            if ($store !== null) {
                return new ResolvedHostContext($store, null);
            }
        }

        return ResolvedHostContext::empty();
    }

    public function extractSubdomain(string $host): ?string
    {
        $parts = explode('.', $host);
        if (count($parts) >= 3) {
            return $parts[0];
        }

        return null;
    }
}
