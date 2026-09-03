<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Modules\Marketplace\Enums\MarketplaceCommercialModel;
use Modules\Marketplace\Enums\MerchantOfRecordRole;

interface MarketplaceCommercialPolicyInterface
{
    public function resolveModel(int $tenantId, ?int $storeId = null): MarketplaceCommercialModel;

    public function doesPlatformCollectCustomerFunds(int $tenantId, ?int $storeId = null): bool;

    public function doesPlatformOweVendorPayable(int $tenantId, ?int $storeId = null): bool;

    public function doesPlatformRecognizeCommission(int $tenantId, ?int $storeId = null): bool;

    public function merchantOfRecordRole(int $tenantId, ?int $storeId = null): MerchantOfRecordRole;
}
