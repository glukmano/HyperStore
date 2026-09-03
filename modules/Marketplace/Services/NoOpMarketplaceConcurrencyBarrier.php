<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Modules\Marketplace\Contracts\MarketplaceConcurrencyBarrierInterface;

final class NoOpMarketplaceConcurrencyBarrier implements MarketplaceConcurrencyBarrierInterface
{
    public function wait(string $barrierName, int $timeoutMs = 2000): void
    {
        // No-op in production
    }
}
