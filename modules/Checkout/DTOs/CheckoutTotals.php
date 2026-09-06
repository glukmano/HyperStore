<?php

declare(strict_types=1);

namespace Modules\Checkout\DTOs;

use InvalidArgumentException;
use Modules\Pricing\ValueObjects\MoneyValue;

final readonly class CheckoutTotals
{
    public MoneyValue $storeValueApplied;

    public MoneyValue $amountDue;

    /**
     * @param  ?MoneyValue  $storeValueApplied  Owner Delta §16/C.25: a
     *                                          reduction of the amount
     *                                          actually tendered, applied
     *                                          AFTER tax — never a
     *                                          merchandise discount that
     *                                          would alter the taxable
     *                                          base. Defaults to zero —
     *                                          every existing call site is
     *                                          byte-for-byte unaffected.
     */
    public function __construct(
        public MoneyValue $merchandiseSubtotal,
        public MoneyValue $lineDiscounts,
        public MoneyValue $cartDiscounts,
        public MoneyValue $shippingOriginal,
        public MoneyValue $shippingDiscount,
        public MoneyValue $shippingFinal,
        public MoneyValue $taxTotal,
        public MoneyValue $grandTotal,
        ?MoneyValue $storeValueApplied = null
    ) {
        // Strict reconciliation assertion — grandTotal continues to
        // represent the full commercial value of the Order, unaffected by
        // Store Value (C.25).
        $expectedGrandTotalMinor = $this->merchandiseSubtotal->getMinorAmount()
            - $this->lineDiscounts->getMinorAmount()
            - $this->cartDiscounts->getMinorAmount()
            + $this->shippingFinal->getMinorAmount()
            + $this->taxTotal->getMinorAmount();

        if ($this->grandTotal->getMinorAmount() !== $expectedGrandTotalMinor) {
            throw new InvalidArgumentException(
                "CheckoutTotals reconciliation failed: Subtotal({$this->merchandiseSubtotal->getMinorAmount()}) - LineDiscounts({$this->lineDiscounts->getMinorAmount()}) - CartDiscounts({$this->cartDiscounts->getMinorAmount()}) + ShippingFinal({$this->shippingFinal->getMinorAmount()}) + Tax({$this->taxTotal->getMinorAmount()}) !== GrandTotal({$this->grandTotal->getMinorAmount()}) [Expected: {$expectedGrandTotalMinor}]."
            );
        }

        $this->storeValueApplied = $storeValueApplied ?? MoneyValue::zero($this->grandTotal->getCurrencyCode());

        $amountDueMinor = $this->grandTotal->getMinorAmount() - $this->storeValueApplied->getMinorAmount();
        if ($amountDueMinor < 0) {
            throw new InvalidArgumentException(
                "CheckoutTotals reconciliation failed: storeValueApplied({$this->storeValueApplied->getMinorAmount()}) exceeds grandTotal({$this->grandTotal->getMinorAmount()})."
            );
        }
        $this->amountDue = MoneyValue::fromMinor($amountDueMinor, $this->grandTotal->getCurrencyCode());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'merchandise_subtotal' => $this->merchandiseSubtotal->getMinorAmount(),
            'line_discounts' => $this->lineDiscounts->getMinorAmount(),
            'cart_discounts' => $this->cartDiscounts->getMinorAmount(),
            'shipping_original' => $this->shippingOriginal->getMinorAmount(),
            'shipping_discount' => $this->shippingDiscount->getMinorAmount(),
            'shipping_final' => $this->shippingFinal->getMinorAmount(),
            'tax_total' => $this->taxTotal->getMinorAmount(),
            'grand_total' => $this->grandTotal->getMinorAmount(),
            'store_value_applied' => $this->storeValueApplied->getMinorAmount(),
            'amount_due' => $this->amountDue->getMinorAmount(),
            'currency' => $this->grandTotal->getCurrencyCode(),
        ];
    }
}
