<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Models\Vendor;

interface VendorOperationalLifecycleServiceInterface
{
    public function approveVendor(int $tenantId, int $vendorId): Vendor;

    public function suspendVendor(int $tenantId, int $vendorId): Vendor;

    public function reactivateVendor(int $tenantId, int $vendorId): Vendor;

    public function transitionStatus(int $tenantId, int $vendorId, VendorOperationalStatus $targetStatus): Vendor;
}
