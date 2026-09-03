<?php

declare(strict_types=1);

use Modules\Order\Models\OrderItem;
use Modules\Order\Services\DecimalReturnAllocationService;

beforeEach(function (): void {
    $this->allocator = new DecimalReturnAllocationService;
});

test('allocates integer return quantity accurately with cumulative difference of floor', function (): void {
    $item = new OrderItem([
        'quantity' => '3.00000000',
        'subtotal_minor' => 10000,
        'discount_minor' => 1000,
        'tax_minor' => 1900,
        'commission_amount_minor' => 1500,
    ]);

    // Return 1 of 3
    $alloc1 = $this->allocator->calculateItemAllocation($item, '0.00000000', '1.00000000');
    expect($alloc1['refund_subtotal_minor'])->toBe(3333)
        ->and($alloc1['refund_discount_reversal_minor'])->toBe(333)
        ->and($alloc1['refund_tax_minor'])->toBe(633)
        ->and($alloc1['vendor_commission_reversal_minor'])->toBe(500)
        ->and($alloc1['net_customer_refund_minor'])->toBe(3333 - 333 + 633)
        ->and($alloc1['vendor_payable_debit_minor'])->toBe((3333 - 333 + 633) - 500);

    // Return 2nd of 3
    $alloc2 = $this->allocator->calculateItemAllocation($item, '1.00000000', '1.00000000');
    expect($alloc2['refund_subtotal_minor'])->toBe(3333)
        ->and($alloc2['refund_discount_reversal_minor'])->toBe(333)
        ->and($alloc2['refund_tax_minor'])->toBe(633)
        ->and($alloc2['vendor_commission_reversal_minor'])->toBe(500);

    // Return 3rd of 3 (Final unit captures remainders!)
    $alloc3 = $this->allocator->calculateItemAllocation($item, '2.00000000', '1.00000000');
    expect($alloc3['refund_subtotal_minor'])->toBe(3334)
        ->and($alloc3['refund_discount_reversal_minor'])->toBe(334)
        ->and($alloc3['refund_tax_minor'])->toBe(634)
        ->and($alloc3['vendor_commission_reversal_minor'])->toBe(500);

    // Sum of all three allocations strictly equals original totals
    $sumSubtotal = $alloc1['refund_subtotal_minor'] + $alloc2['refund_subtotal_minor'] + $alloc3['refund_subtotal_minor'];
    $sumDiscount = $alloc1['refund_discount_reversal_minor'] + $alloc2['refund_discount_reversal_minor'] + $alloc3['refund_discount_reversal_minor'];
    $sumTax = $alloc1['refund_tax_minor'] + $alloc2['refund_tax_minor'] + $alloc3['refund_tax_minor'];
    $sumCommission = $alloc1['vendor_commission_reversal_minor'] + $alloc2['vendor_commission_reversal_minor'] + $alloc3['vendor_commission_reversal_minor'];

    expect($sumSubtotal)->toBe(10000)
        ->and($sumDiscount)->toBe(1000)
        ->and($sumTax)->toBe(1900)
        ->and($sumCommission)->toBe(1500);
});

test('allocates fractional decimal quantities with conservation', function (): void {
    $item = new OrderItem([
        'quantity' => '1.50000000',
        'subtotal_minor' => 7500,
        'discount_minor' => 500,
        'tax_minor' => 1400,
        'commission_amount_minor' => 700,
    ]);

    // Return 0.5 of 1.5
    $alloc1 = $this->allocator->calculateItemAllocation($item, '0.00000000', '0.50000000');
    expect($alloc1['refund_subtotal_minor'])->toBe(2500)
        ->and($alloc1['refund_discount_reversal_minor'])->toBe(166)
        ->and($alloc1['refund_tax_minor'])->toBe(466)
        ->and($alloc1['vendor_commission_reversal_minor'])->toBe(233);

    // Return remaining 1.0 of 1.5
    $alloc2 = $this->allocator->calculateItemAllocation($item, '0.50000000', '1.00000000');
    expect($alloc2['refund_subtotal_minor'])->toBe(5000)
        ->and($alloc2['refund_discount_reversal_minor'])->toBe(334)
        ->and($alloc2['refund_tax_minor'])->toBe(934)
        ->and($alloc2['vendor_commission_reversal_minor'])->toBe(467);

    // Total conservation
    expect($alloc1['refund_subtotal_minor'] + $alloc2['refund_subtotal_minor'])->toBe(7500)
        ->and($alloc1['refund_discount_reversal_minor'] + $alloc2['refund_discount_reversal_minor'])->toBe(500)
        ->and($alloc1['refund_tax_minor'] + $alloc2['refund_tax_minor'])->toBe(1400)
        ->and($alloc1['vendor_commission_reversal_minor'] + $alloc2['vendor_commission_reversal_minor'])->toBe(700);
});

test('fails closed if cumulative return quantity exceeds item quantity', function (): void {
    $item = new OrderItem([
        'quantity' => '2.00000000',
        'subtotal_minor' => 5000,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'commission_amount_minor' => 0,
    ]);

    $this->allocator->calculateItemAllocation($item, '1.50000000', '0.60000000');
})->throws(InvalidArgumentException::class);
