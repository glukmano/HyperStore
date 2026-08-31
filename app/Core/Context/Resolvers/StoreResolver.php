<?php

declare(strict_types=1);

namespace App\Core\Context\Resolvers;

use App\Core\Context\Contracts\StoreContextInterface;
use App\Core\Context\Contracts\StoreResolverInterface;
use App\Core\Context\Contracts\TenantContextInterface;
use App\Core\Context\DTOs\StoreContext;
use App\Core\Routing\DomainAddressingService;
use App\Core\Stores\Models\Store;
use Illuminate\Http\Request;

class StoreResolver implements StoreResolverInterface
{
    public function __construct(
        private readonly ?Request $request = null,
        private readonly ?DomainAddressingService $domainService = null,
        private readonly ?TenantContextInterface $tenantContext = null,
    ) {}

    public function resolve(): StoreContextInterface
    {
        if ($this->request === null) {
            return StoreContext::unresolved();
        }

        if ($this->domainService !== null) {
            $host = $this->request->getHost();
            $store = $this->domainService->findStoreByHost($host);
            if ($store !== null) {
                return StoreContext::from($store->id, $store->slug);
            }
        }

        $headerStoreId = $this->request->header('X-Store-ID');
        if ($headerStoreId !== null && $headerStoreId !== '') {
            $store = Store::query()->where('id', $headerStoreId)->where('status', 'active')->first();
            if ($store !== null) {
                return StoreContext::from($store->id, $store->slug);
            }
        }

        $headerStoreSlug = $this->request->header('X-Store-Slug');
        if ($headerStoreSlug !== null && $headerStoreSlug !== '') {
            $store = Store::query()->where('slug', $headerStoreSlug)->where('status', 'active')->first();
            if ($store !== null) {
                return StoreContext::from($store->id, $store->slug);
            }
        }

        $routeStore = $this->request->route('store') ?? $this->request->route('store_slug');
        if (is_string($routeStore) && $routeStore !== '') {
            $query = Store::query()->where(is_numeric($routeStore) ? 'id' : 'slug', $routeStore)->where('status', 'active');
            if ($this->tenantContext !== null && $this->tenantContext->isResolved()) {
                $query->where('tenant_id', $this->tenantContext->getId());
            }
            $store = $query->first();
            if ($store !== null) {
                return StoreContext::from($store->id, $store->slug);
            }
        }

        if ($this->tenantContext !== null && $this->tenantContext->isResolved()) {
            $defaultStore = Store::query()
                ->where('tenant_id', $this->tenantContext->getId())
                ->where('status', 'active')
                ->first();

            if ($defaultStore !== null) {
                return StoreContext::from($defaultStore->id, $defaultStore->slug);
            }
        }

        return StoreContext::unresolved();
    }
}
