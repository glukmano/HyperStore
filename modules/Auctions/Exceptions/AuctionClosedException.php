<?php

declare(strict_types=1);

namespace Modules\Auctions\Exceptions;

final class AuctionClosedException extends AuctionException
{
    public static function forAuction(int $auctionId): self
    {
        return new self("Auction [{$auctionId}] is not open for bidding.");
    }
}
