<?php

declare(strict_types=1);

namespace Modules\Auctions\Exceptions;

/**
 * Owner Delta §7: an Auction Product must not remain normally purchasable
 * through an ordinary Add-to-Cart path while bidding is open.
 */
final class AuctionNotEligibleForCartException extends AuctionException
{
    public static function forProduct(int $productId): self
    {
        return new self("Product [{$productId}] is currently part of an active/scheduled Auction and cannot be added to a normal Cart.");
    }
}
