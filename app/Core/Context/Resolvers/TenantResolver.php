<?php

declare(strict_types=1);

namespace App\Core\Context\Resolvers;

use App\Core\Context\Contracts\TenantContextInterface;
use App\Core\Context\Contracts\TenantResolverInterface;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Routing\DomainAddressingService;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Http\Request;

class TenantResolver implements TenantResolverInterface
{
    public function __construct(
        private readonly ?Request $request = null,
        private readonly ?DomainAddressingService $domainService = null,
    ) {}

    public function resolve(): TenantContextInterface
    {
        if ($this->request === null) {
            return TenantContext::unresolved();
        }

        if ($this->domainService !== null) {
            $host = $this->request->getHost();
            $store = $this->domainService->findStoreByHost($host);
            if ($store !== null) {
                $tenant = $store->tenant ?? Tenant::find($store->tenant_id);
                if ($tenant !== null && $tenant->isActive()) {
                    return TenantContext::from($tenant->id, $tenant->name);
                }
            }
        }

        $headerTenantId = $this->request->header('X-Tenant-ID');
        if ($headerTenantId !== null && $headerTenantId !== '') {
            $tenant = Tenant::query()->where('id', $headerTenantId)->where('status', 'active')->first();
            if ($tenant !== null) {
                return TenantContext::from($tenant->id, $tenant->name);
            }
        }

        $headerTenantSlug = $this->request->header('X-Tenant-Slug');
        if ($headerTenantSlug !== null && $headerTenantSlug !== '') {
            $tenant = Tenant::query()->where('slug', $headerTenantSlug)->where('status', 'active')->first();
            if ($tenant !== null) {
                return TenantContext::from($tenant->id, $tenant->name);
            }
        }

        $routeTenant = $this->request->route('tenant') ?? $this->request->route('tenant_slug');
        if (is_string($routeTenant) && $routeTenant !== '') {
            $tenant = Tenant::query()
                ->where(is_numeric($routeTenant) ? 'id' : 'slug', $routeTenant)
                ->where('status', 'active')
                ->first();

            if ($tenant !== null) {
                return TenantContext::from($tenant->id, $tenant->name);
            }
        }

        return TenantContext::unresolved();
    }
}
