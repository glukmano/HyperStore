<?php

declare(strict_types=1);

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Fulfillment\Contracts\FulfillmentExecutionServiceInterface;
use Modules\Fulfillment\Enums\FulfillmentMode;
use Modules\Fulfillment\Enums\FulfillmentStatus;
use Modules\Fulfillment\Enums\ShipmentStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\SellerOrder;
use Modules\Order\Models\SellerOrderItem;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->executionService = app(FulfillmentExecutionServiceInterface::class);

    $this->tenant = Tenant::create([
        'name' => 'Fulfillment Tenant',
        'slug' => 'fulf-tenant',
        'is_active' => true,
    ]);

    $this->store = Store::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Fulf Store',
        'slug' => 'fulf-store',
        'status' => 'active',
        'url' => 'https://fulf.example.com',
    ]);

    $this->market = Market::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'DE',
        'name' => 'Germany',
        'default_currency_code' => 'EUR',
        'default_locale_code' => 'de',
        'timezone' => 'Europe/Berlin',
        'is_active' => true,
    ]);

    $this->channel = Channel::create([
        'name' => 'Web Channel',
        'type' => 'website',
        'handle' => 'web-fulf',
        'is_active' => true,
    ]);

    $cart = Cart::create([
        'tenant_id' => $this->tenant->id,
        'store_id' => $this->store->id,
        'market_id' => $this->market->id,
        'channel_id' => $this->channel->id,
        'currency' => 'EUR',
        'locale' => 'de',
        'status' => 'active',
    ]);

    $session = CheckoutSession::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'cart_id' => $cart->id,
        'store_id' => $this->store->id,
        'market_id' => $this->market->id,
        'channel_id' => $this->channel->id,
        'currency' => 'EUR',
        'locale' => 'de',
        'state' => 'ready_for_order',
    ]);

    $this->order = Order::create([
        'order_number' => 'ORD-FULF-001',
        'tenant_id' => $this->tenant->id,
        'store_id' => $this->store->id,
        'market_id' => $this->market->id,
        'channel_id' => $this->channel->id,
        'checkout_id' => $session->id,
        'currency' => 'EUR',
        'locale' => 'de',
        'order_status' => 'placed',
        'payment_status' => 'paid',
        'fulfillment_status' => 'unfulfilled',
        'merchandise_subtotal_minor' => 10000,
        'discount_total_minor' => 0,
        'tax_total_minor' => 0,
        'shipping_total_minor' => 0,
        'grand_total_minor' => 10000,
        'commercial_model_snapshot' => 'platform_as_merchant_of_record',
        'customer_snapshot' => ['email' => 'fulf@example.com'],
        'version' => 1,
        'placed_at' => now(),
    ]);

    $this->item1 = OrderItem::create([
        'tenant_id' => $this->tenant->id,
        'order_id' => $this->order->id,
        'sku_snapshot' => 'SKU-001',
        'name_snapshot' => 'Item 1',
        'product_type_snapshot' => 'physical',
        'requires_shipping_snapshot' => true,
        'quantity' => '2.00000000',
        'unit_price_minor' => 2500,
        'subtotal_minor' => 5000,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 5000,
    ]);

    $this->item2 = OrderItem::create([
        'tenant_id' => $this->tenant->id,
        'order_id' => $this->order->id,
        'sku_snapshot' => 'SKU-002',
        'name_snapshot' => 'Item 2',
        'product_type_snapshot' => 'physical',
        'requires_shipping_snapshot' => true,
        'quantity' => '1.00000000',
        'unit_price_minor' => 5000,
        'subtotal_minor' => 5000,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 5000,
    ]);

    $this->sellerOrder = SellerOrder::create([
        'uuid' => (string) Str::uuid(),
        'tenant_id' => $this->tenant->id,
        'store_id' => $this->store->id,
        'order_id' => $this->order->id,
        'seller_order_number' => 'ORD-FULF-001-PLT',
        'seller_type' => 'platform',
        'vendor_id' => null,
        'commercial_model' => 'platform_as_merchant_of_record',
        'currency' => 'EUR',
        'subtotal_minor' => 10000,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'shipping_original_minor' => 0,
        'shipping_discount_minor' => 0,
        'shipping_final_minor' => 0,
        'total_minor' => 10000,
        'commission_total_minor' => 0,
        'status' => 'open',
    ]);

    SellerOrderItem::create([
        'tenant_id' => $this->tenant->id,
        'seller_order_id' => $this->sellerOrder->id,
        'order_item_id' => $this->item1->id,
        'quantity' => '2.00000000',
        'subtotal_minor' => 5000,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 5000,
        'commission_minor' => 0,
    ]);

    SellerOrderItem::create([
        'tenant_id' => $this->tenant->id,
        'seller_order_id' => $this->sellerOrder->id,
        'order_item_id' => $this->item2->id,
        'quantity' => '1.00000000',
        'subtotal_minor' => 5000,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => 5000,
        'commission_minor' => 0,
    ]);
});

test('creates atomic fulfillments across standard fulfillment modes', function (): void {
    $groups = [
        [
            'mode' => FulfillmentMode::OWN_STOCK->value,
            'items' => [
                ['order_item_id' => $this->item1->id, 'quantity' => '2.00000000'],
            ],
        ],
        [
            'mode' => FulfillmentMode::DIGITAL->value,
            'items' => [
                ['order_item_id' => $this->item2->id, 'quantity' => '1.00000000'],
            ],
        ],
    ];

    $fulfillments = $this->executionService->createFulfillments($this->sellerOrder, $groups);

    expect($fulfillments)->toHaveCount(2)
        ->and($fulfillments[0]->mode)->toBe(FulfillmentMode::OWN_STOCK->value)
        ->and($fulfillments[0]->parent_fulfillment_id)->toBeNull()
        ->and($fulfillments[0]->items)->toHaveCount(1)
        ->and($fulfillments[1]->mode)->toBe(FulfillmentMode::DIGITAL->value);
});

test('creates hybrid fulfillment with child decomposition', function (): void {
    $groups = [
        [
            'mode' => FulfillmentMode::HYBRID->value,
            'items' => [
                ['order_item_id' => $this->item1->id, 'quantity' => '2.00000000'],
                ['order_item_id' => $this->item2->id, 'quantity' => '1.00000000'],
            ],
            'children' => [
                [
                    'mode' => FulfillmentMode::OWN_STOCK->value,
                    'items' => [
                        ['order_item_id' => $this->item1->id, 'quantity' => '2.00000000'],
                    ],
                ],
                [
                    'mode' => FulfillmentMode::MADE_TO_ORDER->value,
                    'items' => [
                        ['order_item_id' => $this->item2->id, 'quantity' => '1.00000000'],
                    ],
                ],
            ],
        ],
    ];

    $fulfillments = $this->executionService->createFulfillments($this->sellerOrder, $groups);

    expect($fulfillments)->toHaveCount(1);
    $parent = $fulfillments[0];

    expect($parent->mode)->toBe(FulfillmentMode::HYBRID->value)
        ->and($parent->supplier_id)->toBeNull()
        ->and($parent->inventory_location_id)->toBeNull()
        ->and($parent->children)->toHaveCount(2);

    $child1 = $parent->children[0];
    $child2 = $parent->children[1];

    expect($child1->parent_fulfillment_id)->toBe($parent->id)
        ->and($child1->mode)->toBe(FulfillmentMode::OWN_STOCK->value)
        ->and($child2->parent_fulfillment_id)->toBe($parent->id)
        ->and($child2->mode)->toBe(FulfillmentMode::MADE_TO_ORDER->value);
});

test('dispatches shipment and transitions fulfillment status to shipped', function (): void {
    $groups = [
        [
            'mode' => FulfillmentMode::OWN_STOCK->value,
            'items' => [
                ['order_item_id' => $this->item1->id, 'quantity' => '2.00000000'],
            ],
        ],
    ];

    $fulfillments = $this->executionService->createFulfillments($this->sellerOrder, $groups);
    $f = $fulfillments[0];

    expect($f->status)->toBe(FulfillmentStatus::PENDING->value);

    $shipment = $this->executionService->shipFulfillment(
        fulfillment: $f,
        carrierCode: 'DHL',
        trackingNumber: 'DHL-987654321',
        trackingUrl: 'https://dhl.com/track/DHL-987654321'
    );

    expect($shipment->carrier_code)->toBe('DHL')
        ->and($shipment->tracking_number)->toBe('DHL-987654321')
        ->and($shipment->status)->toBe(ShipmentStatus::MANIFESTED->value)
        ->and($shipment->shipped_at)->not->toBeNull();

    $f->refresh();
    expect($f->status)->toBe(FulfillmentStatus::SHIPPED->value)
        ->and($f->tracking_number)->toBe('DHL-987654321')
        ->and($f->carrier_code)->toBe('DHL')
        ->and($f->shipped_at)->not->toBeNull();
});
