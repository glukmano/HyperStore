<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Middleware;

use App\Core\Context\ContextManager;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TenantIsolationMiddleware
{
    public function __construct(
        private readonly ContextManager $contextManager,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->contextManager->hasTenant()) {
            return response()->json(['message' => 'Unresolved Tenant context'], 400);
        }

        $tenantId = $this->contextManager->getTenant()->getId();
        $user = $request->user();

        if ($user instanceof User) {
            if ($user->isSuperAdmin()) {
                return $next($request);
            }

            if ($tenantId !== null && ! $user->isMemberOfTenant((int) $tenantId)) {
                return response()->json(['message' => 'Forbidden: You do not have access to this tenant.'], 403);
            }
        }

        return $next($request);
    }
}
