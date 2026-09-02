<?php

declare(strict_types=1);

namespace Modules\Order\Contracts;

use DateTimeZone;

interface OrderNumberGeneratorInterface
{
    /**
     * Generates an atomic, unique sequential order number for the tenant on the given business date.
     * Format: ORD-YYYYMMDD-000001 (6 digits, expandable beyond 999999).
     */
    public function generate(int $tenantId, DateTimeZone $businessTimezone): string;
}
