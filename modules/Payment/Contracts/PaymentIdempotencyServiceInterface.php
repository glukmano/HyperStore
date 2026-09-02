<?php

declare(strict_types=1);

namespace Modules\Payment\Contracts;

use Closure;
use Modules\Payment\Models\PaymentOperationKey;

interface PaymentIdempotencyServiceInterface
{
    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  Closure(PaymentOperationKey): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function execute(
        int $tenantId,
        int $orderId,
        ?int $paymentId,
        string $operationType,
        ?string $idempotencyKey,
        array $requestPayload,
        Closure $callback
    ): array;
}
