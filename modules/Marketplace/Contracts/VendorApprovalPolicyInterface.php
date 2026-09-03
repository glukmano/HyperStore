<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Modules\Marketplace\Models\Vendor;

interface VendorApprovalPolicyInterface
{
    public function canAutoApprove(Vendor $vendor): bool;
}
