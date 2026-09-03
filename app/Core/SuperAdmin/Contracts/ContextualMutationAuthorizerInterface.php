<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Contracts;

interface ContextualMutationAuthorizerInterface
{
    /**
     * Executes a mutating operation under an authoritative membership lock.
     * Asserts that the actor holds an active membership and required role.
     *
     * @template T
     *
     * @param  callable(): T  $mutation
     * @return T
     */
    public function executeTenantAuthorized(int $tenantId, int $userId, string $requiredRole, callable $mutation): mixed;
}
