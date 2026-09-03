<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Contracts;

use Modules\Catalog\Models\ProductVariant;
use Modules\Dropshipping\Models\SupplierOffer;

interface SupplierRoutingEngineInterface
{
    /**
     * @param  string  $quantity  Decimal string
     * @return array{
     *     selected_offer: ?SupplierOffer,
     *     normalized_cost_minor: ?int,
     *     audit_snapshot: array<string, mixed>,
     *     candidate_count: int
     * }
     */
    public function routeVariant(
        int $tenantId,
        ?int $vendorId,
        ProductVariant $variant,
        string $quantity,
        string $targetCurrency,
        ?string $deliveryCountryCode = null
    ): array;
}
