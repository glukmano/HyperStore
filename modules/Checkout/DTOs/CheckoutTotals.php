<?php

declare(strict_types=1);

namespace Modules\Checkout\DTOs;

use InvalidArgumentException;
use Modules\Pricing\ValueObjects\MoneyValue;

final readonly class CheckoutTotals
{
    public function __construct(
        public MoneyValue $merchandiseSubtotal,
        public MoneyValue $lineDiscounts,
        public MoneyValue $cartDiscounts,
        public MoneyValue $shippingOriginal,
        public MoneyValue $shippingDiscount,
        public MoneyValue $shippingFinal,
        public MoneyValue $taxTotal,
        public MoneyValue $grandTotal
    ) {
        // Strict reconciliation assertion
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
            'currency' => $this->grandTotal->getCurrencyCode(),
        ];
    }
}
