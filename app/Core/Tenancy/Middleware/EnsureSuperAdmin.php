<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Forbidden: Super Admin access required.'], 403);
        }

        return $next($request);
    }
}
