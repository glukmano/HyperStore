<?php

declare(strict_types=1);

namespace Modules\Auctions\Enums;

enum AuctionStatus: string
{
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Ended = 'ended';
    case Settled = 'settled';
    case Cancelled = 'cancelled';
}
