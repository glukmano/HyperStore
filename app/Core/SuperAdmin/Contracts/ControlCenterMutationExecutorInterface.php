<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Contracts;

use Illuminate\Http\Request;

interface ControlCenterMutationExecutorInterface
{
    /**
     * Executes a tenant/context-scoped mutation.
     * If X-Impersonation-Token is present, routes through ImpersonationService::executeAuthorized()
     * and supplies the effective target user ID to the mutation callback.
     * Otherwise, executes using the authenticated user's ID.
     *
     * @template T
     *
     * @param  callable(int $effectiveUserId): T  $mutation
     * @return T
     */
    public function execute(Request $request, string $action, callable $mutation): mixed;

    /**
     * Executes a Super Admin platform-level mutation under Super Admin row lock.
     *
     * @template T
     *
     * @param  callable(): T  $mutation
     * @return T
     */
    public function executeSuperAdmin(Request $request, callable $mutation): mixed;
}
