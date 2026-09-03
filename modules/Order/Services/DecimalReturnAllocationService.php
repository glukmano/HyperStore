<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use Modules\Order\Models\OrderItem;

class DecimalReturnAllocationService
{
    /**
     * @param  string  $previouslyApprovedQty  Decimal string (e.g. "1.00000000")
     * @param  string  $quantityToApprove  Decimal string (e.g. "0.50000000")
     * @return array{
     *     refund_subtotal_minor: int,
     *     refund_discount_reversal_minor: int,
     *     refund_tax_minor: int,
     *     net_customer_refund_minor: int,
     *     vendor_payable_debit_minor: int,
     *     vendor_commission_reversal_minor: int
     * }
     */
    public function calculateItemAllocation(
        OrderItem $orderItem,
        string $previouslyApprovedQty,
        string $quantityToApprove
    ): array {
        $totalQtyDec = BigDecimal::of((string) $orderItem->quantity);
        $prevQtyDec = BigDecimal::of($previouslyApprovedQty);
        $newQtyDec = BigDecimal::of($quantityToApprove);

        if ($newQtyDec->isNegativeOrZero()) {
            throw new InvalidArgumentException("Quantity to approve must be positive, got [{$quantityToApprove}].");
        }

        $cumQtyDec = $prevQtyDec->plus($newQtyDec);

        if ($cumQtyDec->compareTo($totalQtyDec) > 0) {
            throw new InvalidArgumentException(
                "Cumulative return quantity [{$cumQtyDec}] exceeds OrderItem quantity [{$totalQtyDec}]."
            );
        }

        $subtotal = $orderItem->subtotal_minor;
        $discount = $orderItem->discount_minor;
        $tax = $orderItem->tax_minor;
        $commission = (int) ($orderItem->commission_amount_minor ?? 0);

        $deltaSubtotal = $this->cumulativeDiffFloor($subtotal, $totalQtyDec, $prevQtyDec, $cumQtyDec);
        $deltaDiscount = $this->cumulativeDiffFloor($discount, $totalQtyDec, $prevQtyDec, $cumQtyDec);
        $deltaTax = $this->cumulativeDiffFloor($tax, $totalQtyDec, $prevQtyDec, $cumQtyDec);
        $deltaCommission = $this->cumulativeDiffFloor($commission, $totalQtyDec, $prevQtyDec, $cumQtyDec);

        $netCustomerRefund = $deltaSubtotal - $deltaDiscount + $deltaTax;
        $vendorPayableDebit = $netCustomerRefund - $deltaCommission;

        return [
            'refund_subtotal_minor' => $deltaSubtotal,
            'refund_discount_reversal_minor' => $deltaDiscount,
            'refund_tax_minor' => $deltaTax,
            'net_customer_refund_minor' => $netCustomerRefund,
            'vendor_payable_debit_minor' => $vendorPayableDebit,
            'vendor_commission_reversal_minor' => $deltaCommission,
        ];
    }

    private function cumulativeDiffFloor(
        int $totalAmount,
        BigDecimal $totalQty,
        BigDecimal $prevQty,
        BigDecimal $cumQty
    ): int {
        if ($totalAmount === 0) {
            return 0;
        }

        $amtDec = BigDecimal::of($totalAmount);

        // Previous cumulative allocated: floor(prevQty * totalAmount / totalQty)
        $prevAlloc = $prevQty->isZero()
            ? 0
            : (int) (string) $prevQty->multipliedBy($amtDec)->dividedBy($totalQty, 0, RoundingMode::Down);

        // Current cumulative allocated:
        // If cumQty == totalQty, full conservation of original totalAmount
        $cumAlloc = $cumQty->compareTo($totalQty) === 0
            ? $totalAmount
            : (int) (string) $cumQty->multipliedBy($amtDec)->dividedBy($totalQty, 0, RoundingMode::Down);

        return $cumAlloc - $prevAlloc;
    }
}
