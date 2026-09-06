<?php

declare(strict_types=1);

namespace Modules\Subscriptions\Enums;

/**
 * D.43: active -> (charge fails) -> past_due -> (grace expires, still
 * unpaid) -> suspended; past_due -> (retry succeeds) -> active; suspended
 * -> (payment resolved) -> active; any -> (cancel) -> cancelled.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Grace = 'grace';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
}
