<?php

declare(strict_types=1);

namespace Modules\Subscriptions\Enums;

/**
 * Owner Delta §11: this row is created (claimed) BEFORE any charge
 * attempt, with status='claimed' from the very first INSERT — not merely
 * for successful attempts. `unknown` is an explicit, deliberate outcome for
 * a provider timeout — never inferred, never silently retried as if it
 * were a fresh attempt.
 */
enum SubscriptionRenewalStatus: string
{
    case Claimed = 'claimed';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Unknown = 'unknown';
}
