<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Illuminate\Support\Facades\DB;
use Modules\Marketplace\Contracts\MarketplaceConcurrencyBarrierInterface;
use Modules\Marketplace\Contracts\VendorOperationalLifecycleServiceInterface;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Exceptions\VendorOperationalStatusException;
use Modules\Marketplace\Models\Vendor;

final readonly class VendorOperationalLifecycleService implements VendorOperationalLifecycleServiceInterface
{
    public function __construct(
        private MarketplaceConcurrencyBarrierInterface $barrier = new NoOpMarketplaceConcurrencyBarrier
    ) {}

    public function approveVendor(int $tenantId, int $vendorId): Vendor
    {
        return DB::transaction(function () use ($tenantId, $vendorId): Vendor {
            /** @var Vendor $vendor */
            $vendor = Vendor::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($vendorId);

            if ($vendor->operational_status !== VendorOperationalStatus::PendingApproval) {
                throw VendorOperationalStatusException::invalidTransition(
                    $vendor->operational_status->value,
                    VendorOperationalStatus::Active->value
                );
            }

            $this->barrier->wait('vendor_operational_status_transition');

            $vendor->operational_status = VendorOperationalStatus::Active;
            $vendor->save();

            return $vendor;
        });
    }

    public function suspendVendor(int $tenantId, int $vendorId): Vendor
    {
        return $this->transitionStatus($tenantId, $vendorId, VendorOperationalStatus::Suspended);
    }

    public function reactivateVendor(int $tenantId, int $vendorId): Vendor
    {
        return DB::transaction(function () use ($tenantId, $vendorId): Vendor {
            /** @var Vendor $vendor */
            $vendor = Vendor::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($vendorId);

            if ($vendor->operational_status !== VendorOperationalStatus::Suspended) {
                throw VendorOperationalStatusException::invalidTransition(
                    $vendor->operational_status->value,
                    VendorOperationalStatus::Active->value
                );
            }

            $this->barrier->wait('vendor_operational_status_transition');

            $vendor->operational_status = VendorOperationalStatus::Active;
            $vendor->save();

            return $vendor;
        });
    }

    public function transitionStatus(int $tenantId, int $vendorId, VendorOperationalStatus $targetStatus): Vendor
    {
        return DB::transaction(function () use ($tenantId, $vendorId, $targetStatus): Vendor {
            /** @var Vendor $vendor */
            $vendor = Vendor::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($vendorId);

            if (! $vendor->operational_status->canTransitionTo($targetStatus)) {
                throw VendorOperationalStatusException::invalidTransition(
                    $vendor->operational_status->value,
                    $targetStatus->value
                );
            }

            $this->barrier->wait('vendor_operational_status_transition');

            $vendor->operational_status = $targetStatus;
            $vendor->save();

            return $vendor;
        });
    }
}
