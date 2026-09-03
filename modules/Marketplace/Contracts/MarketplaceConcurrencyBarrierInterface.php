<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

interface MarketplaceConcurrencyBarrierInterface
{
    public function wait(string $barrierName, int $timeoutMs = 2000): void;
}
