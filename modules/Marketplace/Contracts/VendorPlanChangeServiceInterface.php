<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Modules\Marketplace\Models\Vendor;

interface VendorPlanChangeServiceInterface
{
    public function changePlan(int $tenantId, int $vendorId, int $targetPlanId): Vendor;
}
