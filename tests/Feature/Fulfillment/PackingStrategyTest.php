<?php

declare(strict_types=1);

namespace Tests\Feature\Fulfillment;

use Modules\Fulfillment\Contracts\PackingStrategyInterface;
use Modules\Fulfillment\DTOs\FulfillmentItemLine;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\ValueObjects\Weight;
use Tests\TestCase;

class PackingStrategyTest extends TestCase
{
    public function test_default_packing_strategy_splits_when_max_weight_exceeded(): void
    {
        /** @var PackingStrategyInterface $packer */
        $packer = app(PackingStrategyInterface::class);

        // 2 items of 20kg each = 40kg total > 30kg max -> should split into 2 packages
        $items = [
            new FulfillmentItemLine(productId: 1, variantId: null, quantity: 2, unitPrice: MoneyValue::fromMinor(1000, 'CHF'), unitWeight: Weight::of('20.0000', 'kg'), isShippable: true),
        ];

        $packages = $packer->pack($items);
        $this->assertCount(2, $packages);
        $this->assertSame('20.0000', $packages[0]->totalWeight->toKg());
        $this->assertSame('20.0000', $packages[1]->totalWeight->toKg());
    }
}
