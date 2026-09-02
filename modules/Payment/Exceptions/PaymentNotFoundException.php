<?php

declare(strict_types=1);

namespace Modules\Payment\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentNotFoundException extends RuntimeException
{
    public static function forUuid(string $uuid): self
    {
        return new self("Payment with UUID [{$uuid}] not found.");
    }

    public static function forOrder(string $identifier): self
    {
        return new self("Order [{$identifier}] not found.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 404);
    }
}
