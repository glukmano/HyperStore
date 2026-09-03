<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Modules\Marketplace\Contracts\VendorPayableAvailabilityPolicyInterface;
use Modules\Marketplace\Exceptions\CrossTenantMarketplaceException;
use Modules\Marketplace\Exceptions\InvalidVendorPayableAvailabilityPolicyException;
use Modules\Marketplace\Exceptions\MissingVendorPayableAvailabilityPolicyException;

final class VendorPayableAvailabilityPolicy implements VendorPayableAvailabilityPolicyInterface
{
    public const int MAX_HOLD_DAYS = 365;

    public function getHoldDays(int $tenantId, ?int $storeId = null): int
    {
        if ($storeId !== null) {
            /** @var Store|null $store */
            $store = Store::find($storeId);

            if ($store === null) {
                throw new CrossTenantMarketplaceException("Store [{$storeId}] does not exist.");
            }

            if ($store->tenant_id !== $tenantId) {
                throw CrossTenantMarketplaceException::storeMismatch((int) $store->tenant_id, $tenantId);
            }

            $storeSettings = $store->settings ?? [];
            if (is_array($storeSettings)
                && isset($storeSettings['marketplace'])
                && is_array($storeSettings['marketplace'])
                && array_key_exists('payable_hold_days', $storeSettings['marketplace'])
                && $storeSettings['marketplace']['payable_hold_days'] !== null
            ) {
                return $this->validateHoldDays($storeSettings['marketplace']['payable_hold_days'], "store [{$storeId}]");
            }
        }

        /** @var Tenant|null $tenant */
        $tenant = Tenant::find($tenantId);
        if ($tenant !== null) {
            $tenantSettings = $tenant->settings ?? [];
            if (is_array($tenantSettings)
                && isset($tenantSettings['marketplace'])
                && is_array($tenantSettings['marketplace'])
                && array_key_exists('payable_hold_days', $tenantSettings['marketplace'])
                && $tenantSettings['marketplace']['payable_hold_days'] !== null
            ) {
                return $this->validateHoldDays($tenantSettings['marketplace']['payable_hold_days'], "tenant [{$tenantId}]");
            }
        }

        throw MissingVendorPayableAvailabilityPolicyException::forScope($tenantId, $storeId);
    }

    public function calculateAvailableAt(int $tenantId, ?int $storeId = null, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $baseDate = ($from ?? CarbonImmutable::now('UTC'))->setTimezone('UTC');
        $holdDays = $this->getHoldDays($tenantId, $storeId);

        return $baseDate->addDays($holdDays);
    }

    private function validateHoldDays(mixed $value, string $scope): int
    {
        if (! is_int($value)) {
            throw InvalidVendorPayableAvailabilityPolicyException::invalidValue($scope, $value, 'not an integer');
        }

        if ($value < 0) {
            throw InvalidVendorPayableAvailabilityPolicyException::invalidValue($scope, $value, 'negative');
        }

        if ($value > self::MAX_HOLD_DAYS) {
            throw InvalidVendorPayableAvailabilityPolicyException::exceedsMaximum($scope, $value, self::MAX_HOLD_DAYS);
        }

        return $value;
    }
}
