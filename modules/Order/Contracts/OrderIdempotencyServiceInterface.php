<?php

declare(strict_types=1);

namespace Modules\Order\Contracts;

use Closure;

interface OrderIdempotencyServiceInterface
{
    /**
     * Executes an order-domain operation with aggregate-scoped durable idempotency.
     *
     * @param  array<string, mixed>  $requestPayload
     * @return array<string, mixed>
     */
    public function execute(
        int $tenantId,
        ?int $checkoutId,
        ?int $orderId,
        string $operationType,
        ?string $idempotencyKey,
        array $requestPayload,
        Closure $callback
    ): array;
}
