<?php

declare(strict_types=1);

namespace Modules\Auctions\Exceptions;

final class BidTooLowException extends AuctionException
{
    public static function forAmount(int $amountMinor, int $floorMinor): self
    {
        return new self("Bid amount [{$amountMinor}] is below the required minimum [{$floorMinor}].");
    }
}
