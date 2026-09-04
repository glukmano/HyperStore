<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Core\Channels\Models\Channel;
use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Catalog\Actions\CreateProductAction;
use Modules\Catalog\DTOs\ProductData;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Customers\Models\GiftRegistryPurchase;
use Modules\Customers\Services\GiftRegistryService;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Events\OrderStatusChanged;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Tests\TestCase;

/**
 * Proves gift-registry purchase tracking is derived from the real Order
 * domain event (OrderStatusChanged, dimension=order_status, toStatus=
 * completed) rather than a mutable counter, and requires an explicit
 * gift-registry-intent signal on the order line (customization_metadata_snapshot)
 * — a plain purchase of the same product by someone else must never count.
 */
class GiftRegistryPurchaseTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $registryOwner;

    private User $gifter;

    private Product $product;

    private Store $registryStore;

    private Market $registryMarket;

    private Channel $registryChannel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['slug' => 'registry-tenant', 'name' => 'Registry Tenant', 'status' => 'active']);
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'Main', 'slug' => 'registry-store', 'status' => 'active']);
        $this->registryMarket = Market::create([
            'tenant_id' => $this->tenant->id, 'code' => 'US', 'name' => 'United States',
            'default_currency_code' => 'USD', 'default_locale_code' => 'en', 'timezone' => 'America/New_York', 'is_active' => true,
        ]);
        $this->registryChannel = Channel::create(['name' => 'Web', 'type' => 'website', 'handle' => 'registry-web', 'is_active' => true]);

        $this->registryOwner = User::create(['name' => 'Owner', 'email' => 'owner-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);
        $this->gifter = User::create(['name' => 'Gifter', 'email' => 'gifter-'.uniqid().'@test.com', 'password' => bcrypt('x'), 'status' => 'active', 'is_super_admin' => false]);

        $this->product = app(CreateProductAction::class)->execute(new ProductData(
            tenantId: $this->tenant->id,
            productType: 'physical',
            sku: 'REGISTRY-SKU-1',
            translations: ['en' => ['name' => 'Registry Product']],
        ));

        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id, $this->tenant->name));
        $this->registryStore = $store;
    }

    private function createOrderWithItem(User $buyer, ?int $registryItemId): OrderItem
    {
        $cart = Cart::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->registryStore->id,
            'market_id' => $this->registryMarket->id,
            'channel_id' => $this->registryChannel->id,
            'currency' => 'USD',
            'currency_code' => 'USD',
            'channel' => 'web',
            'status' => 'active',
        ]);

        $checkout = CheckoutSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'cart_id' => $cart->id,
            'user_id' => $buyer->id,
            'store_id' => $this->registryStore->id,
            'market_id' => $this->registryMarket->id,
            'channel_id' => $this->registryChannel->id,
            'currency' => 'USD',
            'locale' => 'en',
            'state' => 'completed',
            'expires_at' => now()->addHour(),
        ]);

        $order = Order::create([
            'uuid' => (string) Str::uuid(),
            'order_number' => 'ORD-REG-'.uniqid(),
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->registryStore->id,
            'market_id' => $this->registryMarket->id,
            'channel_id' => $this->registryChannel->id,
            'checkout_id' => $checkout->id,
            'user_id' => $buyer->id,
            'currency' => 'USD',
            'locale' => 'en',
            'order_status' => OrderStatus::PLACED->value,
            'payment_status' => 'unpaid',
            'fulfillment_status' => 'unfulfilled',
            'merchandise_subtotal_minor' => 5000,
            'discount_total_minor' => 0,
            'shipping_total_minor' => 0,
            'tax_total_minor' => 0,
            'grand_total_minor' => 5000,
            'customer_snapshot' => ['email' => $buyer->email],
            'placed_at' => now(),
        ]);

        return OrderItem::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'sku_snapshot' => 'REGISTRY-SKU-1',
            'name_snapshot' => 'Registry Product',
            'quantity' => '1.00000000',
            'unit_price_minor' => 5000,
            'subtotal_minor' => 5000,
            'total_minor' => 5000,
            'customization_metadata_snapshot' => $registryItemId !== null ? ['gift_registry_item_id' => $registryItemId] : null,
        ]);
    }

    public function test_completing_an_order_with_gift_registry_intent_records_the_purchase_and_increments_progress(): void
    {
        $registryService = app(GiftRegistryService::class);
        $registry = $registryService->create($this->registryOwner, 'Wedding', 'wedding', null);
        $registryItem = $registryService->addItem($registry, $this->product->id, null, 3);

        $orderItem = $this->createOrderWithItem($this->gifter, $registryItem->id);
        $order = $orderItem->order()->first();

        OrderStatusChanged::dispatch($order, 'order_status', OrderStatus::PLACED->value, OrderStatus::COMPLETED->value);

        $registryItem->refresh();
        $this->assertSame(1, $registryItem->quantity_purchased);
        $this->assertSame(2, $registryItem->remainingQuantity());

        $purchase = GiftRegistryPurchase::query()->where('registry_item_id', $registryItem->id)->sole();
        $this->assertSame($this->gifter->id, $purchase->purchaser_user_id);
        $this->assertSame($orderItem->id, $purchase->order_item_id);
    }

    public function test_a_plain_purchase_of_the_same_product_without_gift_intent_never_counts(): void
    {
        $registryService = app(GiftRegistryService::class);
        $registry = $registryService->create($this->registryOwner, 'Wedding', 'wedding', null);
        $registryItem = $registryService->addItem($registry, $this->product->id, null, 3);

        // Someone else buys the same product for themselves — no gift-registry
        // metadata on the order line at all.
        $orderItem = $this->createOrderWithItem($this->gifter, null);
        $order = $orderItem->order()->first();

        OrderStatusChanged::dispatch($order, 'order_status', OrderStatus::PLACED->value, OrderStatus::COMPLETED->value);

        $registryItem->refresh();
        $this->assertSame(0, $registryItem->quantity_purchased);
        $this->assertSame(0, GiftRegistryPurchase::query()->count());
    }

    public function test_a_non_completing_status_change_does_not_record_a_purchase(): void
    {
        $registryService = app(GiftRegistryService::class);
        $registry = $registryService->create($this->registryOwner, 'Wedding', 'wedding', null);
        $registryItem = $registryService->addItem($registry, $this->product->id, null, 3);

        $orderItem = $this->createOrderWithItem($this->gifter, $registryItem->id);
        $order = $orderItem->order()->first();

        OrderStatusChanged::dispatch($order, 'order_status', OrderStatus::PLACED->value, OrderStatus::PROCESSING->value);

        $registryItem->refresh();
        $this->assertSame(0, $registryItem->quantity_purchased);
    }
}
