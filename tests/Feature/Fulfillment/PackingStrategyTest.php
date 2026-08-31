<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use Modules\Fulfillment\DTOs\FulfillmentItemLine;
use Modules\Fulfillment\DTOs\PackingResult;
use Modules\Fulfillment\Services\DefaultPackingService;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\ValueObjects\Weight;
use Tests\TestCase;

class PackingStrategyTest extends TestCase
{
    private DefaultPackingService $packer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->packer = new DefaultPackingService;
    }

    public function test_single_line_quantity_is_split_across_packages_when_exceeding_max_weight(): void
    {
        // 2 units of 20kg each = 40kg total. Max package is 30kg.
        // Expected: 2 packages of 20kg each.
        $items = [
            new FulfillmentItemLine(productId: 1, variantId: null, quantity: 2, unitPrice: MoneyValue::fromMinor(1000, 'CHF'), unitWeight: Weight::of('20.0000', 'kg'), isShippable: true),
        ];

        $packages = $this->packer->pack($items);
        $this->assertIsArray($packages);
        $this->assertCount(2, $packages);
        $this->assertSame('20.0000', $packages[0]->totalWeight->toKg());
        $this->assertSame('20.0000', $packages[1]->totalWeight->toKg());
    }

    public function test_oversized_single_unit_returns_structured_packing_failure(): void
    {
        // Single unit of 40kg exceeds 30kg max capacity.
        $items = [
            new FulfillmentItemLine(productId: 99, variantId: null, quantity: 1, unitPrice: MoneyValue::fromMinor(1000, 'CHF'), unitWeight: Weight::of('40.0000', 'kg'), isShippable: true),
        ];

        $result = $this->packer->pack($items);
        $this->assertInstanceOf(PackingResult::class, $result);
        $this->assertFalse($result->isSuccessful);
        $this->assertSame('oversized_unit', $result->failure?->reason);
    }

    public function test_incompatible_shipping_classes_are_separated_into_different_packages(): void
    {
        // Class 1 (Fragile) and Class 2 (Hazardous)
        $items = [
            new FulfillmentItemLine(productId: 1, variantId: null, quantity: 1, unitPrice: MoneyValue::fromMinor(1000, 'CHF'), unitWeight: Weight::of('5.0', 'kg'), isShippable: true, shippingClassId: 1),
            new FulfillmentItemLine(productId: 2, variantId: null, quantity: 1, unitPrice: MoneyValue::fromMinor(1000, 'CHF'), unitWeight: Weight::of('5.0', 'kg'), isShippable: true, shippingClassId: 2),
        ];

        $packages = $this->packer->pack($items);
        $this->assertIsArray($packages);
        $this->assertCount(2, $packages);
    }
}
