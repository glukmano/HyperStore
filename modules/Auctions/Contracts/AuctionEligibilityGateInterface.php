<?php

declare(strict_types=1);

namespace Modules\Auctions\Contracts;

use Modules\Auctions\Exceptions\AuctionNotEligibleForCartException;

/**
 * Owner Delta §7: the ONE place that decides whether a Product can be
 * added to an ordinary Cart right now. While a Product's Auction is
 * `scheduled` or `active`, ordinary Add-to-Cart is rejected outright — the
 * only route to a Checkout for that Product is the system-generated winner
 * Checkout created by Auction settlement.
 */
interface AuctionEligibilityGateInterface
{
    /**
     * @throws AuctionNotEligibleForCartException
     */
    public function assertEligibleForCart(int $tenantId, int $productId): void;
}
