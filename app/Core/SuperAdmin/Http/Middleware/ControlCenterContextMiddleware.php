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
        /** @var ?User $actor */
        $actor = $request->user();
        if ($actor === null) {
            throw UnauthorizedContextException::unauthenticated();
        }

        // 1. Check for Impersonation Token
        $impersonationToken = $request->header('X-Impersonation-Token');
        $impersonationSession = null;
        $isImpersonating = false;

        if ($impersonationToken !== null && is_string($impersonationToken) && trim($impersonationToken) !== '') {
            $impersonationSession = $this->impersonationService->authenticateToken($impersonationToken);

            // Verify authenticated actor matches impersonator
            if ($actor->id !== $impersonationSession->impersonator_user_id) {
                throw UnauthorizedContextException::invalidContext('Authenticated user is not the authorized impersonator for this token.');
            }

            // Load and verify target User
            /** @var ?User $targetUser */
            $targetUser = User::find($impersonationSession->target_user_id);
            if ($targetUser === null || $targetUser->status !== 'active') {
                throw UnauthorizedContextException::invalidContext('Impersonation target user is not valid or active.');
            }

            $effectiveUser = $targetUser;
            $isImpersonating = true;

            // Set effective user context to target user
            $this->contextManager->setUser(UserContext::from($targetUser->id, $targetUser->email));
            $request->attributes->set('impersonator_user_id', $actor->id);
            $request->attributes->set('impersonation_session', $impersonationSession);
        } else {
            $effectiveUser = $actor;
            $this->contextManager->setUser(UserContext::from($actor->id, $actor->email));
        }

        // Effective Super Admin status: ONLY when NOT impersonating AND actor is Super Admin!
        // An impersonated user NEVER inherits the impersonator's Super Admin privileges.
        $effectiveIsSuperAdmin = ! $isImpersonating && $effectiveUser->isSuperAdmin();

        // 2. Resolve requested Tenant with strict containment under impersonation
        $rawTenant = $request->route('tenant') ?? $request->header('X-Tenant-Id');
        $requestedTenantId = null;
        if ($rawTenant instanceof Tenant) {
            $requestedTenantId = $rawTenant->id;
        } elseif (is_numeric($rawTenant)) {
            $requestedTenantId = (int) $rawTenant;
        }

        // Upper-bound containment:
        // If session specifies tenant_id, request cannot escape that tenant
        if ($isImpersonating && $impersonationSession->tenant_id !== null) {
            if ($requestedTenantId !== null && $requestedTenantId !== $impersonationSession->tenant_id) {
                throw UnauthorizedContextException::invalidContext("Requested Tenant [{$requestedTenantId}] does not match impersonation session Tenant [{$impersonationSession->tenant_id}].");
            }
            $tenantId = $impersonationSession->tenant_id;
        } else {
            $tenantId = $requestedTenantId;
        }

        if ($tenantId !== null) {
            /** @var ?Tenant $tenant */
            $tenant = Tenant::find($tenantId);
            if ($tenant === null) {
                throw UnauthorizedContextException::invalidContext("Tenant [{$tenantId}] does not exist.");
            }

            // Verify active tenant status (Only non-impersonated Super Admins are exempt to manage/reactivate)
            if (! $effectiveIsSuperAdmin && ! $tenant->isActive()) {
                $statusVal = is_string($tenant->status) ? $tenant->status : $tenant->status->value;
                throw TenantSuspendedException::forTenant($tenant->id, $statusVal);
            }

            // Verify active tenant license (Only non-impersonated Super Admins are exempt to manage/override)
            if (! $effectiveIsSuperAdmin) {
                /** @var ?TenantLicense $license */
                $license = TenantLicense::where('tenant_id', $tenant->id)->first();
                if ($license === null || ! $license->isActive()) {
                    $statusStr = $license !== null ? $license->status : 'missing';
                    throw TenantLicenseInactiveException::forTenant($tenant->id, $statusStr);
                }
            }

            // Verify membership in tenant: EFFECTIVE USER must hold membership in tenant!
            // (Only non-impersonated Super Admins are exempt)
            if (! $effectiveIsSuperAdmin && ! $effectiveUser->isMemberOfTenant($tenant->id)) {
                throw UnauthorizedContextException::invalidContext("User does not hold membership in Tenant [{$tenant->id}].");
            }

            $this->contextManager->setTenant(TenantContext::from($tenant->id, $tenant->slug));

            // 3. Resolve requested Store with strict containment under impersonation
            $rawStore = $request->route('store') ?? $request->header('X-Store-Id');
            $requestedStoreId = null;
            if ($rawStore instanceof Store) {
                $requestedStoreId = $rawStore->id;
            } elseif (is_numeric($rawStore)) {
                $requestedStoreId = (int) $rawStore;
            }

            if ($isImpersonating && $impersonationSession->store_id !== null) {
                if ($requestedStoreId !== null && $requestedStoreId !== $impersonationSession->store_id) {
                    throw UnauthorizedContextException::invalidContext("Requested Store [{$requestedStoreId}] does not match impersonation session Store [{$impersonationSession->store_id}].");
                }
                $storeId = $impersonationSession->store_id;
            } else {
                $storeId = $requestedStoreId;
            }

            if ($storeId !== null) {
                /** @var ?Store $store */
                $store = Store::where('tenant_id', $tenant->id)->find($storeId);
                if ($store === null) {
                    throw UnauthorizedContextException::invalidContext("Store [{$storeId}] does not belong to Tenant [{$tenant->id}].");
                }
                $this->contextManager->setStore(StoreContext::from($store->id, $store->slug));
            }

            // 4. Resolve requested Vendor with strict containment under impersonation
            $rawVendor = $request->route('vendor') ?? $request->header('X-Vendor-Id');
            $requestedVendorId = null;
            if ($rawVendor instanceof Vendor) {
                $requestedVendorId = $rawVendor->id;
            } elseif (is_numeric($rawVendor)) {
                $requestedVendorId = (int) $rawVendor;
            }

            if ($isImpersonating && $impersonationSession->vendor_id !== null) {
                if ($requestedVendorId !== null && $requestedVendorId !== $impersonationSession->vendor_id) {
                    throw UnauthorizedContextException::invalidContext("Requested Vendor [{$requestedVendorId}] does not match impersonation session Vendor [{$impersonationSession->vendor_id}].");
                }
                $vendorId = $impersonationSession->vendor_id;
            } else {
                $vendorId = $requestedVendorId;
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
