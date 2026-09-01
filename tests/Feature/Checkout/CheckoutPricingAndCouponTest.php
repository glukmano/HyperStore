<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Currency;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Promotions\Models\Coupon;
use Modules\Promotions\Models\Promotion;
use Modules\Promotions\Models\PromotionAction;
use Tests\TestCase;

class CheckoutPricingAndCouponTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private Channel $channel;

    private User $user;

    private Product $productA;

    private Product $productB;

    private CartServiceInterface $cartService;

    private CheckoutOrchestratorInterface $checkoutOrchestrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Currency::firstOrCreate(['code' => 'CHF'], ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_active' => true]);

        $this->tenant = Tenant::create(['name' => 'Checkout Pricing Tenant', 'slug' => 'cp-tenant', 'status' => 'active']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'CP_S1', 'name' => 'Store 1', 'slug' => 'cp-s1', 'status' => 'active']);
        $this->market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'CH', 'name' => 'Switzerland', 'default_currency_code' => 'CHF', 'default_locale_code' => 'en', 'is_active' => true]);
        $this->channel = Channel::create(['name' => 'Web', 'handle' => 'web', 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->store->id, 'channel_id' => $this->channel->id, 'is_active' => true]);

        $this->user = User::factory()->create();

        $this->productA = Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Product A',
            'slug' => 'product-a',
            'sku' => 'PROD-A',
            'product_type' => 'physical',
            'status' => 'active',
            'weight_kg' => 1.0,
        ]);

        $this->productB = Product::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Product B',
            'slug' => 'product-b',
            'sku' => 'PROD-B',
            'product_type' => 'physical',
            'status' => 'active',
            'weight_kg' => 2.0,
        ]);

        // Standard Price Book
        $pb = PriceBook::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'STANDARD',
            'name' => 'Standard',
            'currency' => 'CHF',
            'status' => 'active',
            'priority' => 1,
        ]);

        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $this->productA->id, 'amount_minor' => 5000, 'currency' => 'CHF', 'status' => 'active']); // 50.00 CHF
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $this->productB->id, 'amount_minor' => 10000, 'currency' => 'CHF', 'status' => 'active']); // 100.00 CHF

        $this->cartService = app(CartServiceInterface::class);
        $this->checkoutOrchestrator = app(CheckoutOrchestratorInterface::class);
    }

    public function test_authoritative_server_pricing_and_exact_totals_reconciliation(): void
    {
        $ctx = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user->id
        );
        $cart = $this->cartService->getOrCreateActiveCart($ctx);

        $this->cartService->addLine($cart, new CartLineItemData(
            productId: $this->productA->id,
            variantId: null,
            quantity: CartQuantity::fromInt(2) // 2 * 50 = 100.00 CHF
        ));

        $session = $this->checkoutOrchestrator->createFromCart($cart);

        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData(
            email: 'customer@example.com',
            firstName: 'John',
            lastName: 'Doe'
        ));

        $session = $this->checkoutOrchestrator->setAddresses($session, new CheckoutAddress(
            recipient: 'John Doe',
            streetLines: ['Bahnhofstrasse 1'],
            city: 'Zurich',
            countryCode: 'CH',
            postalCode: '8001'
        ));

        $pricingSnapshot = $session->pricing_snapshot;
        $this->assertNotNull($pricingSnapshot);
        $this->assertSame(10000, $pricingSnapshot['subtotal_minor']);
    }

    public function test_coupon_application_and_discount_calculation(): void
    {
        // Create 20% discount coupon
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => '20% Off',
            'code' => 'SAVE20_PROMO',
            'status' => 'active',
            'priority' => 10,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        PromotionAction::create([
            'promotion_id' => $promo->id,
            'action_type' => 'percentage_discount',
            'parameters' => ['percentage' => 20],
        ]);

        Coupon::create([
            'tenant_id' => $this->tenant->id,
            'promotion_id' => $promo->id,
            'code' => 'SAVE20',
            'status' => 'active',
        ]);

        $ctx = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user->id
        );
        $cart = $this->cartService->getOrCreateActiveCart($ctx);
        $this->cartService->addLine($cart, new CartLineItemData(
            productId: $this->productB->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1) // 100.00 CHF
        ));
        $this->cartService->applyCoupon($cart, 'SAVE20');

        $session = $this->checkoutOrchestrator->createFromCart($cart);

        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData(
            email: 'customer@example.com',
            firstName: 'John',
            lastName: 'Doe'
        ));

        $session = $this->checkoutOrchestrator->setAddresses($session, new CheckoutAddress(
            recipient: 'John Doe',
            streetLines: ['Bahnhofstrasse 1'],
            city: 'Zurich',
            countryCode: 'CH',
            postalCode: '8001'
        ));

        $promoSnapshot = $session->promotion_snapshot;
        $this->assertNotNull($promoSnapshot);
        $this->assertSame(2000, $promoSnapshot['total_discount_minor']); // 20.00 CHF
    }
}
