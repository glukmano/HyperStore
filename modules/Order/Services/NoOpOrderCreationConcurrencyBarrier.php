<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Modules\Order\Contracts\OrderCreationConcurrencyBarrierInterface;

class NoOpOrderCreationConcurrencyBarrier implements OrderCreationConcurrencyBarrierInterface
{
    public function beforeReservationAdoption(int $tenantId, string $reservationKey): void
    {
        // Strict No-Op in production runtime
    }
}
