<?php

declare(strict_types=1);

namespace Modules\Order\Contracts;

use Modules\Order\Enums\OrderActorType;
use Modules\Order\Models\Order;

interface OrderCancellationServiceInterface
{
    /**
     * Cancels an order, releases its retained inventory reservations, and transitions status.
     * Idempotent: repeated cancellation does not double-release stock.
     */
    public function cancel(
        Order $order,
        string $reason,
        OrderActorType $actorType = OrderActorType::SYSTEM,
        ?int $actorId = null,
        ?string $idempotencyKey = null
    ): Order;
}
