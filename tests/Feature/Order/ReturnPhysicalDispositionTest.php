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
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Order\Contracts\MasterOrderSplitServiceInterface;
use Modules\Order\Contracts\ReturnPhysicalDispositionServiceInterface;
use Modules\Order\Contracts\ReturnRequestServiceInterface;
use Modules\Order\Enums\SellerReturnStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\ReturnItem;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(ReferenceDataSeeder::class);

    $this->tenant = Tenant::create(['name' => 'RMA Disp Tenant', 'slug' => 'rma-disp-'.uniqid(), 'status' => 'active']);
    app(LedgerAccountRegistryInterface::class)->ensureRequiredSystemAccounts($this->tenant->id);

    $this->product = app(CreateProductAction::class)->execute(new ProductData(
        tenantId: $this->tenant->id,
        productType: 'physical',
        sku: 'RMA-DISP-'.uniqid(),
        translations: ['en' => ['name' => 'RMA Disposition Product']],
    ));

    $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'S', 'slug' => 'rma-disp-store-'.uniqid(), 'status' => 'active', 'url' => 'https://rma.example.com']);
    $this->market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'DE', 'name' => 'Germany', 'default_currency_code' => 'EUR', 'default_locale_code' => 'de', 'timezone' => 'Europe/Berlin', 'is_active' => true]);
    $this->channel = Channel::create(['name' => 'C', 'type' => 'website', 'handle' => 'rma-disp-'.uniqid(), 'is_active' => true]);

    $cart = Cart::create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'market_id' => $this->market->id, 'channel_id' => $this->channel->id, 'currency' => 'EUR', 'locale' => 'de', 'status' => 'active']);
    $session = CheckoutSession::create(['uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'cart_id' => $cart->id, 'store_id' => $this->store->id, 'market_id' => $this->market->id, 'channel_id' => $this->channel->id, 'currency' => 'EUR', 'locale' => 'de', 'state' => 'ready_for_order']);

    $this->order = Order::create([
        'order_number' => 'ORD-RMADISP-'.uniqid(), 'tenant_id' => $this->tenant->id, 'store_id' => $this->store->id,
        'market_id' => $this->market->id, 'channel_id' => $this->channel->id, 'checkout_id' => $session->id,
        'currency' => 'EUR', 'locale' => 'de', 'order_status' => 'completed', 'payment_status' => 'paid',
        'fulfillment_status' => 'fulfilled', 'merchandise_subtotal_minor' => 3000, 'discount_total_minor' => 0,
        'tax_total_minor' => 0, 'shipping_total_minor' => 0, 'grand_total_minor' => 3000,
        'commercial_model_snapshot' => 'platform_as_merchant_of_record', 'customer_snapshot' => ['email' => 'rma@example.com'],
        'version' => 1, 'placed_at' => now(),
    ]);

    $this->orderItem = OrderItem::create([
        'tenant_id' => $this->tenant->id, 'order_id' => $this->order->id, 'product_id' => $this->product->id,
        'sku_snapshot' => 'RMA-DISP-SKU', 'name_snapshot' => 'RMA Product', 'product_type_snapshot' => 'physical',
        'requires_shipping_snapshot' => false, 'quantity' => '3.00000000', 'unit_price_minor' => 1000,
        'subtotal_minor' => 3000, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 3000, 'vendor_id' => null,
    ]);

    app(MasterOrderSplitServiceInterface::class)->splitOrder($this->order);

    $warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'RMA-WH-'.uniqid(), 'name' => 'RMA WH', 'country_code' => 'CH']);
    $this->destSource = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'RMA-SRC-'.uniqid(), 'name' => 'RMA SRC']);

    $returnRequest = app(ReturnRequestServiceInterface::class)->createReturnRequest(
        tenantId: $this->tenant->id,
        orderId: $this->order->id,
        customerId: null,
        items: [['order_item_id' => $this->orderItem->id, 'quantity' => '3.00000000', 'reason' => 'customer_return', 'condition' => 'unopened']],
    );
    $this->sellerReturn = $returnRequest->sellerReturns->first();

    app(ReturnRequestServiceInterface::class)->approveReturnItem(
        tenantId: $this->tenant->id, sellerReturnId: $this->sellerReturn->id,
        orderItemId: $this->orderItem->id, quantityToApprove: '3.00000000',
    );
});

test('restock disposition receives stock into the explicit destination InventorySource', function (): void {
    $service = app(ReturnPhysicalDispositionServiceInterface::class);

    $result = $service->confirmPhysicalDisposition(
        tenantId: $this->tenant->id,
        sellerReturnId: $this->sellerReturn->id,
        orderItemId: $this->orderItem->id,
        quantityReceived: '3.00000000',
        condition: 'unopened',
        restockAction: 'restock',
        destinationInventorySourceId: $this->destSource->id,
    );

    $stock = StockItem::where('inventory_source_id', $this->destSource->id)->where('product_id', $this->product->id)->first();
    expect($stock)->not->toBeNull()
        ->and($stock->on_hand)->toBe('3.0000')
        ->and($result->status)->toBe(SellerReturnStatus::INSPECTED->value)
        ->and($result->inspected_at)->not->toBeNull();
});

test('quarantine disposition quarantines stock and never touches on_hand', function (): void {
    $service = app(ReturnPhysicalDispositionServiceInterface::class);

    $service->confirmPhysicalDisposition(
        tenantId: $this->tenant->id, sellerReturnId: $this->sellerReturn->id, orderItemId: $this->orderItem->id,
        quantityReceived: '3.00000000', condition: 'damaged', restockAction: 'quarantine',
        destinationInventorySourceId: $this->destSource->id,
    );

    $stock = StockItem::where('inventory_source_id', $this->destSource->id)->where('product_id', $this->product->id)->first();
    expect($stock->quarantined)->toBe('3.0000')
        ->and($stock->on_hand)->toBe('0.0000');
});

test('discard disposition never mutates Inventory sellable stock', function (): void {
    $service = app(ReturnPhysicalDispositionServiceInterface::class);

    $service->confirmPhysicalDisposition(
        tenantId: $this->tenant->id, sellerReturnId: $this->sellerReturn->id, orderItemId: $this->orderItem->id,
        quantityReceived: '3.00000000', condition: 'damaged', restockAction: 'discard',
    );

    expect(StockItem::where('product_id', $this->product->id)->exists())->toBeFalse();
});

test('disposition confirmation is architecturally independent of refund finalization', function (): void {
    // Confirming physical disposition must never itself require or trigger a
    // refund — the two workflows are decoupled by design (ADR-0128).
    $service = app(ReturnPhysicalDispositionServiceInterface::class);

    $result = $service->confirmPhysicalDisposition(
        tenantId: $this->tenant->id, sellerReturnId: $this->sellerReturn->id, orderItemId: $this->orderItem->id,
        quantityReceived: '3.00000000', condition: 'unopened', restockAction: 'restock',
        destinationInventorySourceId: $this->destSource->id,
    );

    expect($result->payment_refund_transaction_id)->toBeNull()
        ->and($result->refund_status)->toBe('pending');
});

test('duplicate disposition confirmation is idempotent and never double-restocks', function (): void {
    $service = app(ReturnPhysicalDispositionServiceInterface::class);

    $service->confirmPhysicalDisposition(
        tenantId: $this->tenant->id, sellerReturnId: $this->sellerReturn->id, orderItemId: $this->orderItem->id,
        quantityReceived: '3.00000000', condition: 'unopened', restockAction: 'restock',
        destinationInventorySourceId: $this->destSource->id,
    );

    $service->confirmPhysicalDisposition(
        tenantId: $this->tenant->id, sellerReturnId: $this->sellerReturn->id, orderItemId: $this->orderItem->id,
        quantityReceived: '3.00000000', condition: 'unopened', restockAction: 'restock',
        destinationInventorySourceId: $this->destSource->id,
    );

    $stock = StockItem::where('inventory_source_id', $this->destSource->id)->where('product_id', $this->product->id)->first();
    expect($stock->on_hand)->toBe('3.0000');
});

test('restock action requires an explicit destination InventorySource', function (): void {
    $service = app(ReturnPhysicalDispositionServiceInterface::class);

    expect(fn () => $service->confirmPhysicalDisposition(
        tenantId: $this->tenant->id, sellerReturnId: $this->sellerReturn->id, orderItemId: $this->orderItem->id,
        quantityReceived: '3.00000000', condition: 'unopened', restockAction: 'restock',
    ))->toThrow(InvalidArgumentException::class);
});

test('scale-8 quantity with non-zero digits beyond scale-4 fails closed', function (): void {
    $service = app(ReturnPhysicalDispositionServiceInterface::class);

    expect(fn () => $service->confirmPhysicalDisposition(
        tenantId: $this->tenant->id, sellerReturnId: $this->sellerReturn->id, orderItemId: $this->orderItem->id,
        quantityReceived: '1.12345678', condition: 'unopened', restockAction: 'restock',
        destinationInventorySourceId: $this->destSource->id,
    ))->toThrow(InvalidArgumentException::class);

    expect(StockItem::where('product_id', $this->product->id)->exists())->toBeFalse();
});

test('return_items restock_action seam is confirmed populated by createReturnRequest', function (): void {
    $item = ReturnItem::where('seller_return_id', $this->sellerReturn->id)->first();
    expect($item->restock_action)->toBe('restock')
        ->and($item->disposed_at)->toBeNull();
});
