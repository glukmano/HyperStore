<?php

declare(strict_types=1);

namespace Modules\Auctions\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Owner Delta §6: bid acceptance and Auction finalization must compare
 * against database-authoritative time, inside the same locked transaction —
 * never PHP `Carbon::now()`/a client-supplied timestamp. Queries the DB
 * connection's own clock directly.
 */
final class DatabaseClock
{
    public static function now(): CarbonImmutable
    {
        /** @var object{now: string} $row */
        $row = DB::selectOne('SELECT CURRENT_TIMESTAMP AS now');

        return CarbonImmutable::parse($row->now)->utc();
    }
}
