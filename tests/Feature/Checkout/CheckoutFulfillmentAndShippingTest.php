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
use Modules\Checkout\Exceptions\ShippingQuoteExpiredException;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Tests\TestCase;

class CheckoutFulfillmentAndShippingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private Channel $channel;

    private User $user;

    private Product $digitalProduct;

    private Product $physicalProduct;

    private ShippingZone $zone;

    private ShippingMethod $method;

    private CartServiceInterface $cartService;

    private CheckoutOrchestratorInterface $checkoutOrchestrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Currency::firstOrCreate(['code' => 'CHF'], ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_active' => true]);

        $this->tenant = Tenant::create(['name' => 'Fulfillment Tenant', 'slug' => 'ful-tenant', 'status' => 'active']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'FUL_S1', 'name' => 'Store 1', 'slug' => 'ful-s1', 'status' => 'active']);
        $this->market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'CH', 'name' => 'Switzerland', 'default_currency_code' => 'CHF', 'default_locale_code' => 'en', 'is_active' => true]);
        $this->channel = Channel::create(['name' => 'Web', 'handle' => 'web', 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->store->id, 'channel_id' => $this->channel->id, 'is_active' => true]);

        $this->user = User::factory()->create();

        TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'STD_TAX', 'name' => 'Standard Tax', 'is_default' => true]);

        $this->digitalProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'DIGI-1',
            'name' => 'Digital E-Book',
            'slug' => 'digital-ebook',
            'product_type' => 'digital',
            'status' => 'active',
        ]);

        $this->physicalProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'PHYS-1',
            'name' => 'Physical Book',
            'slug' => 'physical-book',
            'product_type' => 'physical',
            'status' => 'active',
            'weight_kg' => 1.0,
        ]);

        $pb = PriceBook::create(['tenant_id' => $this->tenant->id, 'code' => 'STD', 'name' => 'Std', 'currency' => 'CHF', 'status' => 'active', 'priority' => 1]);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $this->digitalProduct->id, 'amount_minor' => 2000, 'currency' => 'CHF', 'status' => 'active']);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $this->physicalProduct->id, 'amount_minor' => 4000, 'currency' => 'CHF', 'status' => 'active']);

        $this->zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_ZONE', 'name' => 'CH Zone', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $this->zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);
        $this->method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'STD_SHIP',
            'name' => 'Standard Shipping',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 1000,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $this->method->id, 'shipping_zone_id' => $this->zone->id]);

        $wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH1', 'name' => 'WH 1', 'country_code' => 'CH', 'status' => 'active']);
        $source = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC1', 'name' => 'Main Warehouse', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);
        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $source->id, 'product_id' => $this->physicalProduct->id, 'on_hand' => 100, 'reserved' => 0]);

        $this->cartService = app(CartServiceInterface::class);
        $this->checkoutOrchestrator = app(CheckoutOrchestratorInterface::class);
    }

    public function test_digital_only_checkout_does_not_require_physical_shipping_or_address(): void
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
            productId: $this->digitalProduct->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1)
        ));

        $session = $this->checkoutOrchestrator->createFromCart($cart);

        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData(
            email: 'digital@example.com',
            firstName: 'Alice',
            lastName: 'Smith'
        ));

        // Mark ready for order directly without shipping address or shipping quote
        $ready = $this->checkoutOrchestrator->markReadyForOrder($session);

        $this->assertSame('ready_for_order', $ready->state);
        $this->assertNull($ready->selectedShippingQuote);
        $this->assertSame(2000, $ready->totals['grand_total']);
    }

    public function test_anti_tamper_shipping_selection_rejects_client_amounts_and_uses_authoritative_server_quote(): void
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
            productId: $this->physicalProduct->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1)
        ));

        $session = $this->checkoutOrchestrator->createFromCart($cart);

        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData(
            email: 'physical@example.com',
            firstName: 'Bob',
            lastName: 'Jones'
        ));

        $session = $this->checkoutOrchestrator->setAddresses($session, new CheckoutAddress(
            recipient: 'Bob Jones',
            streetLines: ['Poststrasse 5'],
            city: 'Bern',
            countryCode: 'CH',
            postalCode: '3000'
        ));

        // Client attempts to submit final_amount = 1 (tampering)
        $tamperedSelection = [
            'method_id' => $this->method->id,
            'method_code' => $this->method->code,
            'final_amount' => 1,
            'original_amount' => 1,
        ];

        $session = $this->checkoutOrchestrator->selectShippingQuote($session, $tamperedSelection);

        // Assert server persisted actual authoritative quote amount (1000 minor = 10.00 CHF), ignoring client's 1 minor
        $selected = $session->selected_shipping_quote;
        $this->assertNotNull($selected);
        $this->assertSame(1000, $selected['original_amount']);
        $this->assertSame(1000, $selected['final_amount']);

        $session = $this->checkoutOrchestrator->reserveInventory($session);

        $ready = $this->checkoutOrchestrator->markReadyForOrder($session);

        $this->assertSame('ready_for_order', $ready->state);
        $this->assertSame(5000, $ready->totals['grand_total']); // 40.00 + 10.00 shipping = 50.00 CHF
    }

    public function test_get_shipping_rates_api_returns_fresh_typed_quotes(): void
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
            productId: $this->physicalProduct->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1)
        ));

        $session = $this->checkoutOrchestrator->createFromCart($cart);
        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData('u@example.com', 'U', '1'));
        $session = $this->checkoutOrchestrator->setAddresses($session, new CheckoutAddress('U 1', ['Poststrasse 1'], 'Bern', 'CH', postalCode: '3000'));

        $rates = $this->checkoutOrchestrator->getShippingRates($session);
        $this->assertNotNull($rates['shipping_result']);
        $this->assertNotEmpty($rates['shipping_result']->quotes);
    }

    public function test_expired_shipping_quote_blocks_ready_for_order_and_requires_requote(): void
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
            productId: $this->physicalProduct->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1)
        ));

        $session = $this->checkoutOrchestrator->createFromCart($cart);
        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData('u@example.com', 'U', '1'));
        $session = $this->checkoutOrchestrator->setAddresses($session, new CheckoutAddress('U 1', ['Poststrasse 1'], 'Bern', 'CH', postalCode: '3000'));
        $session = $this->checkoutOrchestrator->selectShippingQuote($session, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);
        $session = $this->checkoutOrchestrator->reserveInventory($session);

        // Manually simulate expired quote
        $quoteData = $session->selected_shipping_quote;
        $quoteData['expires_at'] = now()->subHour()->toIso8601String();
        $session->selected_shipping_quote = $quoteData;
        $session->save();

        $this->expectException(ShippingQuoteExpiredException::class);
        $this->expectExceptionMessage("SHIPPING_QUOTE_EXPIRED: Selected shipping quote [{$this->method->id}] has expired");

        $this->checkoutOrchestrator->markReadyForOrder($session);
    }
}
