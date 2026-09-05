<?php

declare(strict_types=1);

namespace Modules\Auctions\Enums;

/**
 * Owner Delta §8: exactly one conservative default policy is supported in
 * Phase-20 — re-offering to the next bidder is deliberately deferred (it
 * requires deterministic next-bidder selection, settlement expiry,
 * reservation-release timing, and idempotency guarantees against
 * accidentally granting one bidder two simultaneous active settlement
 * sessions — unnecessary complexity for this phase).
 */
enum UnpaidWinnerPolicy: string
{
    case Cancel = 'cancel';
}
