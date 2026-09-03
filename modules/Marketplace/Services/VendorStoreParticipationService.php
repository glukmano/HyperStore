<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use App\Core\Stores\Models\Store;
use Illuminate\Support\Facades\DB;
use Modules\Marketplace\Contracts\MarketplaceConcurrencyBarrierInterface;
use Modules\Marketplace\Contracts\VendorStoreParticipationServiceInterface;
use Modules\Marketplace\Exceptions\CrossTenantMarketplaceException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorStoreParticipation;

final readonly class VendorStoreParticipationService implements VendorStoreParticipationServiceInterface
{
    public function __construct(
        private MarketplaceConcurrencyBarrierInterface $barrier = new NoOpMarketplaceConcurrencyBarrier
    ) {}

    public function enableParticipation(int $tenantId, int $vendorId, int $storeId): VendorStoreParticipation
    {
        return $this->setParticipationStatus($tenantId, $vendorId, $storeId, true);
    }

    public function disableParticipation(int $tenantId, int $vendorId, int $storeId): VendorStoreParticipation
    {
        return $this->setParticipationStatus($tenantId, $vendorId, $storeId, false);
    }

    private function setParticipationStatus(int $tenantId, int $vendorId, int $storeId, bool $isEnabled): VendorStoreParticipation
    {
        return DB::transaction(function () use ($tenantId, $vendorId, $storeId, $isEnabled): VendorStoreParticipation {
            /** @var Vendor $vendor */
            $vendor = Vendor::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($vendorId);

            $store = Store::find($storeId);
            if ($store === null || $store->tenant_id !== $tenantId) {
                throw new CrossTenantMarketplaceException("Store [{$storeId}] does not belong to tenant [{$tenantId}].");
            }

            $this->barrier->wait('vendor_store_participation_mutating');

            /** @var VendorStoreParticipation $participation */
            $participation = VendorStoreParticipation::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'vendor_id' => $vendor->id,
                    'store_id' => $store->id,
                ],
                [
                    'is_enabled' => $isEnabled,
                ]
            );

            return $participation;
        });
    }
}
