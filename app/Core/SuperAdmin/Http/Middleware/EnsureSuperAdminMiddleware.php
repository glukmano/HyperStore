<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Http\Middleware;

use App\Core\SuperAdmin\Exceptions\UnauthorizedContextException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureSuperAdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ?User $user */
        $user = $request->user();
        if ($user === null || ! $user->isSuperAdmin()) {
            throw UnauthorizedContextException::invalidContext('Super Admin privileges are strictly required for this resource.');
        }

        return $next($request);
    }
}
