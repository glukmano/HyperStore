<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Http\Middleware;

use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\StoreContext;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Context\DTOs\UserContext;
use App\Core\Context\DTOs\VendorContext;
use App\Core\Stores\Models\Store;
use App\Core\SuperAdmin\Contracts\ImpersonationServiceInterface;
use App\Core\SuperAdmin\Exceptions\TenantLicenseInactiveException;
use App\Core\SuperAdmin\Exceptions\TenantSuspendedException;
use App\Core\SuperAdmin\Exceptions\UnauthorizedContextException;
use App\Core\SuperAdmin\Models\TenantLicense;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Modules\Marketplace\Models\Vendor;
use Symfony\Component\HttpFoundation\Response;

final readonly class ControlCenterContextMiddleware
{
    public function __construct(
        private ContextManager $contextManager,
        private ImpersonationServiceInterface $impersonationService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var ?User $user */
        $user = $request->user();
        if ($user === null) {
            throw UnauthorizedContextException::unauthenticated();
        }

        $this->contextManager->setUser(UserContext::from($user->id, $user->email));

        // 1. Check for active Impersonation Token
        $impersonationToken = $request->header('X-Impersonation-Token');
        $impersonationSession = null;
        if ($impersonationToken !== null && is_string($impersonationToken)) {
            $impersonationSession = $this->impersonationService->authenticateToken($impersonationToken);
        }

        // 2. Resolve requested Tenant if provided in route, header, or impersonation session
        $rawTenant = $request->route('tenant') ?? $request->header('X-Tenant-Id') ?? $impersonationSession?->tenant_id;
        $tenantId = null;
        if ($rawTenant instanceof Tenant) {
            $tenantId = $rawTenant->id;
        } elseif (is_numeric($rawTenant)) {
            $tenantId = (int) $rawTenant;
        }

        if ($tenantId !== null) {
            /** @var ?Tenant $tenant */
            $tenant = Tenant::find($tenantId);
            if ($tenant === null) {
                throw UnauthorizedContextException::invalidContext("Tenant [{$tenantId}] does not exist.");
            }

            // Verify active tenant status
            if (! $tenant->isActive()) {
                $statusVal = is_string($tenant->status) ? $tenant->status : $tenant->status->value;
                throw TenantSuspendedException::forTenant($tenant->id, $statusVal);
            }

            // Verify active tenant license
            /** @var ?TenantLicense $license */
            $license = TenantLicense::where('tenant_id', $tenant->id)->first();
            if ($license === null || ! $license->isActive()) {
                $statusStr = $license !== null ? $license->status : 'missing';
                throw TenantLicenseInactiveException::forTenant($tenant->id, $statusStr);
            }

            // Verify user belongs to tenant (unless user is Super Admin or authorized impersonator)
            $isSuperAdmin = $user->isSuperAdmin();
            $isAuthorizedImpersonator = ($impersonationSession !== null && $impersonationSession->impersonator_user_id === $user->id);

            if (! $isSuperAdmin && ! $isAuthorizedImpersonator && ! $user->isMemberOfTenant($tenant->id)) {
                throw UnauthorizedContextException::invalidContext("User does not hold membership in Tenant [{$tenant->id}].");
            }

            $this->contextManager->setTenant(TenantContext::from($tenant->id, $tenant->slug));

            // 3. Resolve requested Store if provided
            $rawStore = $request->route('store') ?? $request->header('X-Store-Id') ?? $impersonationSession?->store_id;
            $storeId = null;
            if ($rawStore instanceof Store) {
                $storeId = $rawStore->id;
            } elseif (is_numeric($rawStore)) {
                $storeId = (int) $rawStore;
            }

            if ($storeId !== null) {
                /** @var ?Store $store */
                $store = Store::where('tenant_id', $tenant->id)->find($storeId);
                if ($store === null) {
                    throw UnauthorizedContextException::invalidContext("Store [{$storeId}] does not belong to Tenant [{$tenant->id}].");
                }
                $this->contextManager->setStore(StoreContext::from($store->id, $store->slug));
            }

            // 4. Resolve requested Vendor if provided
            $rawVendor = $request->route('vendor') ?? $request->header('X-Vendor-Id') ?? $impersonationSession?->vendor_id;
            $vendorId = null;
            if ($rawVendor instanceof Vendor) {
                $vendorId = $rawVendor->id;
            } elseif (is_numeric($rawVendor)) {
                $vendorId = (int) $rawVendor;
            }

            if ($vendorId !== null) {
                /** @var ?Vendor $vendor */
                $vendor = Vendor::where('tenant_id', $tenant->id)->find($vendorId);
                if ($vendor === null) {
                    throw UnauthorizedContextException::invalidContext("Vendor [{$vendorId}] does not belong to Tenant [{$tenant->id}].");
                }
                $this->contextManager->setVendor(VendorContext::from($vendor->id, $vendor->uuid));
            }
        }

        return $next($request);
    }
}
