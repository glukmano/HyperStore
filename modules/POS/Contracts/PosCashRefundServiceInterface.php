<?php

declare(strict_types=1);

namespace Modules\POS\Contracts;

use Modules\Order\Models\Order;

/**
 * Owner Delta §5: cash refund is NOT owned by the Store Value domain.
 * Modules\Order\Services\TenderRefundDispatchService calls this contract
 * when a refund allocation's tender_type is 'cash'. POS/Cash remains
 * responsible for cash drawer movement; this is the orchestration seam,
 * not a second refund engine.
 */
interface PosCashRefundServiceInterface
{
    /**
     * Refunds cash by recording a compensating refund_cash_out movement
     * against an open RegisterSession for the Order's Store. If no
     * RegisterSession is open, falls back to a Store Credit destination
     * (an explicit, documented policy — cash cannot be refunded from a
     * screen with no physical drawer behind it) and returns that outcome.
     *
     * @return array{method: string, register_session_id: ?int}
     */
    public function refundCash(Order $order, int $amountMinor, string $refundEventUuid): array;
}
