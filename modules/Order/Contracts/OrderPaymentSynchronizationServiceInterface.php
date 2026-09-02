<?php

declare(strict_types=1);

namespace Modules\Order\Contracts;

use Modules\Order\Enums\PaymentStatus;
use Modules\Order\Models\Order;

interface OrderPaymentSynchronizationServiceInterface
{
    /**
     * Synchronize authoritative payment status projection into Order.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function syncPaymentStatus(
        int $tenantId,
        int $orderId,
        PaymentStatus $status,
        string $reason,
        array $metadata = []
    ): Order;
}
