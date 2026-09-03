<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Contracts;

use Modules\Dropshipping\Models\PurchaseOrder;
use Modules\Dropshipping\Models\SupplierInvoice;

interface SupplierInvoiceReconciliationServiceInterface
{
    /**
     * @param list<array{
     *     purchase_order_line_id: int,
     *     quantity: string,
     *     unit_cost_minor: int,
     *     tax_minor?: int
     * }> $lines
     */
    public function recordAndReconcileInvoice(
        PurchaseOrder $po,
        string $invoiceNumber,
        array $lines,
        ?int $shippingMinor = null,
        ?int $taxMinor = null
    ): SupplierInvoice;
}
