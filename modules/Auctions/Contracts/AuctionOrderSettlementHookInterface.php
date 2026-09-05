<?php

declare(strict_types=1);

namespace Modules\Auctions\Contracts;

use Modules\Order\Models\Order;

/**
 * Owner Delta §7/§11: called from inside `OrderCreationService`'s existing
 * non-invasive optional hook pattern once the winner's Order is created,
 * transitioning the Auction to `settled` — the historical facts themselves
 * (winning bid, amount, currency) were already frozen onto the OrderItem at
 * this same moment via the originating CartLine's metadata, not by this
 * hook.
 */
interface AuctionOrderSettlementHookInterface
{
    public function settleFromOrder(Order $order): void;
}
