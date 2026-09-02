<?php

declare(strict_types=1);

namespace Modules\Order\Contracts;

use DateTimeZone;

interface BusinessTimezoneResolverInterface
{
    /**
     * Resolves the authoritative business timezone for the given market and store.
     * Precedence:
     * 1. Market configured timezone
     * 2. Store configured timezone (settings['timezone'])
     *
     * Throws InvalidBusinessTimezoneException if unresolvable or invalid.
     */
    public function resolve(int $marketId, int $storeId): DateTimeZone;
}
