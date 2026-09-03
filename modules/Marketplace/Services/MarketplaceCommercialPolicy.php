<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Modules\Marketplace\Contracts\MarketplaceCommercialPolicyInterface;
use Modules\Marketplace\Enums\MarketplaceCommercialModel;
use Modules\Marketplace\Enums\MerchantOfRecordRole;
use Modules\Marketplace\Exceptions\MarketplaceCommercialPolicyException;

final class MarketplaceCommercialPolicy implements MarketplaceCommercialPolicyInterface
{
    public function resolveModel(int $tenantId, ?int $storeId = null): MarketplaceCommercialModel
    {
        // 1. Check store settings override if store provided
        if ($storeId !== null) {
            /** @var Store|null $store */
            $store = Store::where('tenant_id', $tenantId)->find($storeId);
            if ($store !== null) {
                $storeModel = $store->settings['marketplace']['commercial_model'] ?? null;
                if (is_string($storeModel) && MarketplaceCommercialModel::tryFrom($storeModel) !== null) {
                    return MarketplaceCommercialModel::from($storeModel);
                }
            }
        }

        // 2. Check tenant settings default
        /** @var Tenant|null $tenant */
        $tenant = Tenant::find($tenantId);
        if ($tenant !== null) {
            $tenantModel = $tenant->settings['marketplace']['commercial_model'] ?? null;
            if (is_string($tenantModel) && MarketplaceCommercialModel::tryFrom($tenantModel) !== null) {
                return MarketplaceCommercialModel::from($tenantModel);
            }
        }

        // 3. Fail closed if unconfigured
        throw MarketplaceCommercialPolicyException::missingPolicy();
    }

    public function doesPlatformCollectCustomerFunds(int $tenantId, ?int $storeId = null): bool
    {
        return $this->resolveModel($tenantId, $storeId)->doesPlatformCollectCustomerFunds();
    }

    public function doesPlatformOweVendorPayable(int $tenantId, ?int $storeId = null): bool
    {
        return $this->resolveModel($tenantId, $storeId)->doesPlatformOweVendorPayable();
    }

    public function doesPlatformRecognizeCommission(int $tenantId, ?int $storeId = null): bool
    {
        return $this->resolveModel($tenantId, $storeId)->doesPlatformRecognizeCommission();
    }

    public function merchantOfRecordRole(int $tenantId, ?int $storeId = null): MerchantOfRecordRole
    {
        return $this->resolveModel($tenantId, $storeId)->merchantOfRecordRole();
    }
}
