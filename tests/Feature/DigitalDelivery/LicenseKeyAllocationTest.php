<?php

declare(strict_types=1);

namespace Tests\Feature\DigitalDelivery;

use Modules\Catalog\Models\Product;
use Modules\DigitalDelivery\Exceptions\DigitalDeliveryException;
use Modules\DigitalDelivery\Models\LicenseKeyPool;
use Modules\DigitalDelivery\Services\LicenseKeyAllocationService;
use Modules\Order\Models\OrderItem;
use Tests\Feature\Ledger\LedgerTestCaseTrait;
use Tests\TestCase;

/**
 * Owner Delta §14: a license key is an individually distinct secret, never
 * modeled as fungible physical Inventory. The partial unique index on
 * assigned_order_item_id is the actual backstop; this app-level idempotent
 * check is the first line of defense.
 */
class LicenseKeyAllocationTest extends TestCase
{
    use LedgerTestCaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedgerTest();
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'LIC-'.uniqid(),
            'name' => 'Licensed Software',
            'slug' => 'lic-'.uniqid(),
            'product_type' => 'license',
            'status' => 'active',
        ]);
    }

    private function makeOrderItem(int $orderId, int $productId): OrderItem
    {
        return OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $orderId,
            'product_id' => $productId,
            'product_type_snapshot' => 'license',
            'sku_snapshot' => 'LIC-1',
            'name_snapshot' => 'Licensed Software',
            'quantity' => '1',
            'unit_price_minor' => 4000,
            'subtotal_minor' => 4000,
            'total_minor' => 4000,
        ]);
    }

    public function test_allocation_assigns_one_available_key_and_marks_it_assigned(): void
    {
        $product = $this->makeProduct();
        LicenseKeyPool::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'code_encrypted' => 'KEY-AAA', 'status' => 'available']);

        $order = $this->createOrder(4000, 'EUR');
        $item = $this->makeOrderItem($order->id, $product->id);

        $allocated = app(LicenseKeyAllocationService::class)->allocate($this->tenant->id, $product->id, $item->id);

        $this->assertSame('assigned', $allocated->status);
        $this->assertSame($item->id, $allocated->assigned_order_item_id);
    }

    public function test_duplicate_processing_of_the_same_order_item_returns_the_existing_key_not_a_second_allocation(): void
    {
        $product = $this->makeProduct();
        LicenseKeyPool::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'code_encrypted' => 'KEY-BBB', 'status' => 'available']);
        LicenseKeyPool::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'code_encrypted' => 'KEY-CCC', 'status' => 'available']);

        $order = $this->createOrder(4000, 'EUR');
        $item = $this->makeOrderItem($order->id, $product->id);

        $service = app(LicenseKeyAllocationService::class);
        $first = $service->allocate($this->tenant->id, $product->id, $item->id);
        $second = $service->allocate($this->tenant->id, $product->id, $item->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LicenseKeyPool::where('assigned_order_item_id', $item->id)->count());
    }

    public function test_allocation_throws_when_no_key_is_available(): void
    {
        $product = $this->makeProduct();
        $order = $this->createOrder(4000, 'EUR');
        $item = $this->makeOrderItem($order->id, $product->id);

        $this->expectException(DigitalDeliveryException::class);
        app(LicenseKeyAllocationService::class)->allocate($this->tenant->id, $product->id, $item->id);
    }

    public function test_two_different_order_items_never_receive_the_same_key(): void
    {
        $product = $this->makeProduct();
        LicenseKeyPool::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'code_encrypted' => 'KEY-DDD', 'status' => 'available']);

        $order = $this->createOrder(8000, 'EUR');
        $item1 = $this->makeOrderItem($order->id, $product->id);
        $item2 = $this->makeOrderItem($order->id, $product->id);

        $service = app(LicenseKeyAllocationService::class);
        $key1 = $service->allocate($this->tenant->id, $product->id, $item1->id);

        $this->expectException(DigitalDeliveryException::class);
        $service->allocate($this->tenant->id, $product->id, $item2->id);
    }
}
