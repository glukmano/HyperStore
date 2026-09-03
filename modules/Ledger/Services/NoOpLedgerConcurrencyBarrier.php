<?php

declare(strict_types=1);

namespace Modules\Ledger\Services;

use Modules\Ledger\Contracts\LedgerConcurrencyBarrierInterface;

class NoOpLedgerConcurrencyBarrier implements LedgerConcurrencyBarrierInterface
{
    public function wait(string $barrierName, int $timeoutSeconds = 5): void
    {
        // No-op in production
    }
}
