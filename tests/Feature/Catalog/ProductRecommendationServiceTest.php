<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Catalog\Services\ProductRecommendationService;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Tests\TestCase;

class ProductRecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private ProductRecommendationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Reco Tenant', 'slug' => 'reco-tenant']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'S', 'slug' => 'store-'.Str::random(6), 'status' => 'active', 'url' => 'https://s.example.com']);

        $this->service = app(ProductRecommendationService::class);
    }

    private function makeProduct(string $sku): Product
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'product_type' => 'physical',
            'sku' => $sku,
            'status' => 'active',
        ]);

        ProductStoreListing::create([
            'product_id' => $product->id,
            'store_id' => $this->store->id,
            'status' => 'published',
            'visibility' => 'visible',
        ]);

        return $product;
    }

    private function makeOrderWithItems(array $productIds, string $paymentStatus, string $orderStatus = 'placed'): Order
    {
        $market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'M'.Str::random(4), 'name' => 'M', 'default_currency_code' => 'EUR', 'default_locale_code' => 'en', 'timezone' => 'UTC', 'is_active' => true]);
        $channel = Channel::create(['name' => 'Web', 'type' => 'website', 'handle' => 'web-'.Str::random(6), 'is_active' => true]);
        $cart = Cart::create(['tenant_id' => $this->tenant->id, 'store_id' => $this->store->id, 'market_id' => $market->id, 'channel_id' => $channel->id, 'currency' => 'EUR', 'locale' => 'en', 'status' => 'active']);
        $session = CheckoutSession::create(['uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'cart_id' => $cart->id, 'store_id' => $this->store->id, 'market_id' => $market->id, 'channel_id' => $channel->id, 'currency' => 'EUR', 'locale' => 'en', 'state' => 'ready_for_order']);

        $order = Order::create([
            'order_number' => 'ORD-'.Str::random(8),
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'checkout_id' => $session->id,
            'currency' => 'EUR',
            'locale' => 'en',
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'fulfillment_status' => 'unfulfilled',
            'merchandise_subtotal_minor' => 1000 * count($productIds),
            'discount_total_minor' => 0,
            'tax_total_minor' => 0,
            'shipping_total_minor' => 0,
            'grand_total_minor' => 1000 * count($productIds),
            'commercial_model_snapshot' => 'platform_as_merchant_of_record',
            'customer_snapshot' => ['email' => 'buyer@example.com'],
            'version' => 1,
            'placed_at' => now(),
        ]);

        foreach ($productIds as $productId) {
            OrderItem::create([
                'tenant_id' => $this->tenant->id,
                'order_id' => $order->id,
                'product_id' => $productId,
                'sku_snapshot' => 'SKU',
                'name_snapshot' => 'Product',
                'product_type_snapshot' => 'physical',
                'requires_shipping_snapshot' => false,
                'quantity' => '1.00000000',
                'unit_price_minor' => 1000,
                'subtotal_minor' => 1000,
                'discount_minor' => 0,
                'tax_minor' => 0,
                'total_minor' => 1000,
            ]);
        }

        return $order;
    }

    public function test_frequently_bought_together_learns_only_from_paid_orders(): void
    {
        $a = $this->makeProduct('SKU-A');
        $b = $this->makeProduct('SKU-B');
        $cancelledOnlyProduct = $this->makeProduct('SKU-C');

        $this->makeOrderWithItems([$a->id, $b->id], paymentStatus: 'paid');
        $this->makeOrderWithItems([$a->id, $cancelledOnlyProduct->id], paymentStatus: 'pending', orderStatus: 'cancelled');

        $results = $this->service->frequentlyBoughtWith($this->tenant->id, $this->store->id, $a->id);

        $this->assertTrue($results->contains('id', $b->id));
        $this->assertFalse($results->contains('id', $cancelledOnlyProduct->id));
        $this->assertFalse($results->contains('id', $a->id));
    }

    public function test_recommendations_exclude_products_not_published_in_this_store(): void
    {
        $a = $this->makeProduct('SKU-D');
        $b = $this->makeProduct('SKU-E');

        // Unpublish B in this Store.
        ProductStoreListing::where('product_id', $b->id)->where('store_id', $this->store->id)->update(['status' => 'draft']);

        $this->makeOrderWithItems([$a->id, $b->id], paymentStatus: 'paid');

        $results = $this->service->frequentlyBoughtWith($this->tenant->id, $this->store->id, $a->id);

        $this->assertFalse($results->contains('id', $b->id));
    }
}
