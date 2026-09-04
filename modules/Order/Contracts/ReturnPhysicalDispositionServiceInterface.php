<?php

declare(strict_types=1);

namespace Modules\Order\Contracts;

use Modules\Order\Models\SellerReturn;

/**
 * Physical-disposition contract for RMA restock (ADR-0128). Architecturally
 * independent of refund finalization — confirming a physical disposition must
 * never be invoked from, or coupled to, ReturnRefundOrchestrator::finalizeRefund().
 */
interface ReturnPhysicalDispositionServiceInterface
{
    /**
     * @param  string  $restockAction  one of: restock, quarantine, discard, return_to_supplier
     */
    public function confirmPhysicalDisposition(
        int $tenantId,
        int $sellerReturnId,
        int $orderItemId,
        string $quantityReceived,
        string $condition,
        string $restockAction,
        ?int $destinationInventorySourceId = null
    ): SellerReturn;
}
