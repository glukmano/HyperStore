<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use App\Core\Payables\Contracts\PayoutConcurrencyBarrierInterface;

/**
 * Extends the Core PayoutConcurrencyBarrierInterface so the existing
 * NoOpMarketplaceConcurrencyBarrier binding satisfies both without change —
 * AbstractPayoutOrchestrator depends only on the Core interface.
 */
interface MarketplaceConcurrencyBarrierInterface extends PayoutConcurrencyBarrierInterface
{
    public function wait(string $barrierName, int $timeoutMs = 2000): void;
}
