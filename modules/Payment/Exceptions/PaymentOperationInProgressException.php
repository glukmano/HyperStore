<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentOperationInProgressException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("Payment operation with idempotency key [{$key}] is currently in progress. Please retry shortly.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => 'OPERATION_IN_PROGRESS',
            'retryable' => true,
        ], 409);
    }
}
