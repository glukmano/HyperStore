<?php

declare(strict_types=1);

namespace App\Core\Payables\Contracts;

/**
 * A no-op-by-default seam allowing tests to deterministically widen a race
 * window inside AbstractPayoutOrchestrator's locked sections. Marketplace's
 * existing MarketplaceConcurrencyBarrierInterface extends this one so its
 * current binding (NoOpMarketplaceConcurrencyBarrier) satisfies both without
 * any change to how it is bound or tested.
 */
interface PayoutConcurrencyBarrierInterface
{
    public function wait(string $barrierName, int $timeoutMs = 2000): void;
}
