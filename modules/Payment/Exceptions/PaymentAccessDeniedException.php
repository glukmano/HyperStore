<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentAccessDeniedException extends RuntimeException
{
    public static function denied(): self
    {
        return new self('Access denied to payment resource.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 403);
    }
}
