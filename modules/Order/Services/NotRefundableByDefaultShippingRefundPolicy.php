<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Modules\Order\Contracts\ShippingRefundPolicyInterface;
use Modules\Order\Models\SellerReturn;

/**
 * Phase-13 default shipping-refund policy: ShippingRefundPolicy =
 * NOT_REFUNDABLE_BY_DEFAULT.
 *
 * Shipping is never refunded unless an authoritative future policy
 * explicitly approves it. A shipping-refund-approval workflow (who may
 * approve, under what conditions, for how much) is deferred beyond
 * Phase-13; this default keeps the formula's shipping term at an explicit,
 * typed zero rather than an accidental one caused by nobody writing the
 * field.
 */
final class NotRefundableByDefaultShippingRefundPolicy implements ShippingRefundPolicyInterface
{
    public function approvedShippingRefundMinor(SellerReturn $sellerReturn): int
    {
        return 0;
    }
}
