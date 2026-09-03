<?php

declare(strict_types=1);

namespace Modules\Ledger\Contracts;

interface LedgerConcurrencyBarrierInterface
{
    public function wait(string $barrierName, int $timeoutSeconds = 5): void;
}
