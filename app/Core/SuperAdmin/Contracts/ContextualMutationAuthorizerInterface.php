<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Contracts;

interface ContextualMutationAuthorizerInterface
{
    /**
     * Executes a tenant-level mutation under an authoritative TenantUser membership lock.
     *
     * @template T
     *
     * @param  callable(): T  $mutation
     * @return T
     */
    public function executeTenantAuthorized(int $tenantId, int $userId, string $requiredRole, callable $mutation): mixed;

    /**
     * Executes a store-level mutation under an authoritative membership lock.
     *
     * @template T
     *
     * @param  callable(): T  $mutation
     * @return T
     */
    public function executeStoreAuthorized(int $tenantId, int $storeId, int $userId, string $requiredRole, callable $mutation): mixed;

    /**
     * Executes a vendor-level mutation under an authoritative VendorUser membership lock,
     * strictly preserving Phase-11 Vendor RBAC and owner invariants.
     *
     * @template T
     *
     * @param  callable(): T  $mutation
     * @return T
     */
    public function executeVendorAuthorized(int $tenantId, int $vendorId, int $userId, string $requiredRole, callable $mutation): mixed;

    /**
     * Executes a platform super-admin mutation under an authoritative Super Admin lock.
     *
     * @template T
     *
     * @param  callable(): T  $mutation
     * @return T
     */
    public function executeSuperAdminAuthorized(int $userId, callable $mutation): mixed;
}
