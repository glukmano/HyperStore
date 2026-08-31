<?php

declare(strict_types=1);

namespace App\Core\Routing;

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
        $normalizedHost = strtolower(trim($host));

        $domainRecord = StoreDomain::query()
            ->where('domain', $normalizedHost)
            ->where('is_verified', true)
            ->with('store')
            ->first();

        if ($domainRecord !== null && $domainRecord->store !== null && $domainRecord->store->isActive()) {
            return $domainRecord->store;
        }

        $subdomain = $this->extractSubdomain($normalizedHost);
        if ($subdomain !== null && ! $this->isReservedSlug($subdomain)) {
            $store = Store::query()
                ->where('slug', $subdomain)
                ->where('status', 'active')
                ->first();

            if ($store !== null) {
                return $store;
            }
        }

        return null;
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
