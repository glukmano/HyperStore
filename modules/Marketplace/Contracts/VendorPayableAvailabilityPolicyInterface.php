<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Carbon\CarbonImmutable;

interface VendorPayableAvailabilityPolicyInterface
{
    public function getHoldDays(int $tenantId, ?int $storeId = null): int;

    public function calculateAvailableAt(int $tenantId, ?int $storeId = null, ?CarbonImmutable $from = null): CarbonImmutable;
}
