<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Contracts;

use Modules\Dropshipping\Models\PurchaseOrder;
use Modules\Dropshipping\Models\Supplier;

interface ExternalSupplierPortInterface
{
    /**
     * @return array{external_reference: string, raw_response: array<string, mixed>}
     */
    public function submitPurchaseOrder(PurchaseOrder $po): array;

    /**
     * @return array{status: string, tracking_number: ?string, carrier: ?string}
     */
    public function fetchOrderStatus(PurchaseOrder $po): array;

    /**
     * @return array{synced_items: int}
     */
    public function syncOffers(Supplier $supplier): array;
}
