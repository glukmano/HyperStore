<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Modules\Marketplace\Contracts\VendorPayableAvailabilityPolicyInterface;

final class VendorPayableAvailabilityPolicy implements VendorPayableAvailabilityPolicyInterface
{
    public function getHoldDays(int $tenantId, ?int $storeId = null): int
    {
        if ($storeId !== null) {
            /** @var Store|null $store */
            $store = Store::find($storeId);
            if ($store !== null && isset($store->settings['marketplace']['payable_hold_days'])) {
                return (int) $store->settings['marketplace']['payable_hold_days'];
            }
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::find($tenantId);
        if ($tenant !== null && isset($tenant->settings['marketplace']['payable_hold_days'])) {
            return (int) $tenant->settings['marketplace']['payable_hold_days'];
        }

        return 14; // Default return/settlement hold period
    }

    public function calculateAvailableAt(int $tenantId, ?int $storeId = null, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $baseDate = $from ?? CarbonImmutable::now();
        $holdDays = $this->getHoldDays($tenantId, $storeId);

        return $baseDate->addDays($holdDays);
    }
}
