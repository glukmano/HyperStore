<?php

declare(strict_types=1);

namespace App\Core\Payables\Services;

use App\Core\Payables\Contracts\PayoutConcurrencyBarrierInterface;

final class NullPayoutConcurrencyBarrier implements PayoutConcurrencyBarrierInterface
{
    public function wait(string $barrierName, int $timeoutMs = 2000): void
    {
        // Intentionally inert — production default.
    }
}
