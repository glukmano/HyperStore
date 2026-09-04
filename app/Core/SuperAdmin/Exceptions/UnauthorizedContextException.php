<?php

declare(strict_types=1);

namespace App\Core\SuperAdmin\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class UnauthorizedContextException extends RuntimeException
{
    public static function unauthenticated(): self
    {
        return new self('Unauthenticated: Access to Control Center requires valid authentication.');
    }

    public static function invalidContext(string $reason): self
    {
        return new self("Unauthorized context access: {$reason}");
    }

    /**
     * Authenticated-but-unauthorized context denial must never surface as a 500 —
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
