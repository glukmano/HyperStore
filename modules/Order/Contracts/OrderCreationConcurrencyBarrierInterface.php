<?php

declare(strict_types=1);

namespace Modules\Order\Contracts;

interface OrderCreationConcurrencyBarrierInterface
{
    /**
     * Intercepts order creation before a specific inventory reservation is adopted.
     * In production, this is a strict no-op.
     * In concurrency tests, this enables deterministic process synchronization.
     */
    public function beforeReservationAdoption(int $tenantId, string $reservationKey): void;
}
