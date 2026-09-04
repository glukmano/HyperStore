<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Order\Enums\OrderStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;

/**
 * Builds a real, minimal Cart -> CheckoutSession -> Order -> OrderItem chain
 * for tests that need a genuine completed (or any-status) purchase, without
 * running a full checkout orchestration flow.
 */
trait OrderTestFixtures
{
    protected function createCompletedOrderWithItem(Tenant $tenant, User $buyer, Product $product, ?int $vendorId = null): OrderItem
    {
        return $this->createOrderWithItem($tenant, $buyer, $product, OrderStatus::COMPLETED, $vendorId);
    }

    protected function createOrderWithItem(Tenant $tenant, User $buyer, Product $product, OrderStatus $status, ?int $vendorId = null): OrderItem
    {
        $store = Store::query()->where('tenant_id', $tenant->id)->first()
            ?? Store::create(['tenant_id' => $tenant->id, 'name' => 'Fixture Store', 'slug' => 'fixture-store-'.uniqid(), 'status' => 'active']);

        $market = Market::query()->where('tenant_id', $tenant->id)->first()
            ?? Market::create([
                'tenant_id' => $tenant->id, 'code' => 'US', 'name' => 'United States',
                'default_currency_code' => 'USD', 'default_locale_code' => 'en', 'timezone' => 'America/New_York', 'is_active' => true,
            ]);

        $channel = Channel::query()->first()
            ?? Channel::create(['name' => 'Web', 'type' => 'website', 'handle' => 'fixture-web-'.uniqid(), 'is_active' => true]);

        $cart = Cart::create([
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'currency' => 'USD',
            'currency_code' => 'USD',
            'channel' => 'web',
            'status' => 'active',
        ]);

        $checkout = CheckoutSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'cart_id' => $cart->id,
            'user_id' => $buyer->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'currency' => 'USD',
            'locale' => 'en',
            'state' => 'completed',
            'expires_at' => now()->addHour(),
        ]);

        $order = Order::create([
            'uuid' => (string) Str::uuid(),
            'order_number' => 'ORD-FIXTURE-'.uniqid(),
            'tenant_id' => $tenant->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'checkout_id' => $checkout->id,
            'user_id' => $buyer->id,
            'currency' => 'USD',
            'locale' => 'en',
            'order_status' => $status->value,
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
            'tenant_id' => $tenant->id,
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => null,
            'vendor_id' => $vendorId,
            'sku_snapshot' => $product->sku,
            'name_snapshot' => $product->name,
            'quantity' => '1.00000000',
            'unit_price_minor' => 5000,
            'subtotal_minor' => 5000,
            'total_minor' => 5000,
        ]);
    }
}
