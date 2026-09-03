<?php

declare(strict_types=1);

namespace Modules\Order\Contracts;

use Modules\Order\Models\SellerReturn;

/**
 * Decides the "approved_shipping_refund" term of the Phase-13 customer refund
 * formula:
 *
 *   customer_refund_minor = merchandise_refund - discount_reversal + tax_refund
 *                          + approved_shipping_refund
 *
 * This is an explicit policy seam, not an accidental zero: a future,
 * authoritative shipping-refund-approval workflow can be introduced by
 * binding a different implementation of this interface, without touching the
 * return/refund orchestration that consumes it.
 */
interface ShippingRefundPolicyInterface
{
    public function approvedShippingRefundMinor(SellerReturn $sellerReturn): int;
}
