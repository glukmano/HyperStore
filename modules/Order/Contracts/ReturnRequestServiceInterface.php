<?php

declare(strict_types=1);

namespace Modules\Order\Contracts;

use Modules\Order\Models\ReturnRequest;
use Modules\Order\Models\SellerReturn;

interface ReturnRequestServiceInterface
{
    /**
     * @param  list<array{order_item_id: int, quantity: string, condition?: string, reason?: string}>  $items
     */
    public function createReturnRequest(
        int $tenantId,
        int $orderId,
        ?int $customerId,
        array $items,
        ?string $customerNote = null
    ): ReturnRequest;

    public function approveReturnItem(
        int $tenantId,
        int $sellerReturnId,
        int $orderItemId,
        string $quantityToApprove
    ): SellerReturn;
}
