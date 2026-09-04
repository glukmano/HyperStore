<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class TenantLicenseInactiveException extends RuntimeException
{
    public static function forTenant(int $tenantId, string $status): self
    {
        return new self("Tenant [{$tenantId}] license is {$status}; application access is suspended.");
    }

    /**
     * An inactive-license denial must never surface as a 500 —
     * Phase-15 authentication-access completion fix (2026-09-04).
     */
    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 403);
        }

        return response()->view('errors.403', ['exception' => $this], 403);
    }
}
