<?php

declare(strict_types=1);

namespace Modules\Marketplace\Contracts;

use Modules\Marketplace\Models\VendorStoreParticipation;

interface VendorStoreParticipationServiceInterface
{
    public function enableParticipation(int $tenantId, int $vendorId, int $storeId): VendorStoreParticipation;

    public function disableParticipation(int $tenantId, int $vendorId, int $storeId): VendorStoreParticipation;
}
