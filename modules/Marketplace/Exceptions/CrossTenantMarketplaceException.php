<?php

declare(strict_types=1);

namespace Modules\Marketplace\Exceptions;

final class CrossTenantMarketplaceException extends MarketplaceException
{
    public static function storeMismatch(int $storeTenantId, int $targetTenantId): self
    {
        return new self("Store belongs to tenant {$storeTenantId}, but operation is scoped to tenant {$targetTenantId}.");
    }

    public static function listingMismatch(int $listingTenantId, int $targetTenantId): self
    {
        return new self("Vendor listing belongs to tenant {$listingTenantId}, but operation is scoped to tenant {$targetTenantId}.");
    }
}
