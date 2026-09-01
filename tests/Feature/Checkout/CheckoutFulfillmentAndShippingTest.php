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
use Modules\Cart\Models\Cart;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Checkout\Exceptions\ShippingQuoteExpiredException;
use Modules\Checkout\Exceptions\ShippingQuoteStaleException;
use Modules\Checkout\Services\CheckoutShippingOrchestrator;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Promotions\Models\Coupon;
use Modules\Promotions\Models\Promotion;
use Modules\Promotions\Models\PromotionAction;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingRateRule;
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

    private CheckoutShippingOrchestrator $shippingOrchestrator;

    private User $user;

    private Product $digitalProduct;

    private Product $physicalProduct;

    private Product $fractionalProduct;

    private ShippingZone $zone;

    private ShippingMethod $method;

    private Warehouse $wh1;

    private Warehouse $wh2;

    private InventorySource $sourceA;

    private InventorySource $sourceB;

    private StockItem $stockItemA;

    private StockItem $stockItemB;

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

        $this->fractionalProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'FRAC-FABRIC',
            'name' => 'Fabric by Meter',
            'slug' => 'fabric-meter',
            'product_type' => 'custom',
            'status' => 'active',
            'weight_kg' => 0.5,
            'metadata' => ['allows_fractional_quantity' => true],
        ]);

        $pb = PriceBook::create(['tenant_id' => $this->tenant->id, 'code' => 'STD', 'name' => 'Std', 'currency' => 'CHF', 'status' => 'active', 'priority' => 1]);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $this->digitalProduct->id, 'amount_minor' => 2000, 'currency' => 'CHF', 'status' => 'active']);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $this->physicalProduct->id, 'amount_minor' => 4000, 'currency' => 'CHF', 'status' => 'active']);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $this->fractionalProduct->id, 'amount_minor' => 4000, 'currency' => 'CHF', 'status' => 'active']);

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

        $this->wh1 = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH1', 'name' => 'WH 1', 'country_code' => 'CH', 'status' => 'active']);
        $this->wh2 = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH2', 'name' => 'WH 2', 'country_code' => 'CH', 'status' => 'active']);
        $this->sourceA = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->wh1->id, 'code' => 'SRC1', 'name' => 'Warehouse A', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);
        $this->sourceB = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->wh2->id, 'code' => 'SRC2', 'name' => 'Warehouse B', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 20]);
        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->sourceA->id, 'product_id' => $this->physicalProduct->id, 'on_hand' => 100, 'reserved' => 0]);

        $this->cartService = app(CartServiceInterface::class);
        $this->checkoutOrchestrator = app(CheckoutOrchestratorInterface::class);
        $this->shippingOrchestrator = app(CheckoutShippingOrchestrator::class);
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

        $tamperedSelection = [
            'method_id' => $this->method->id,
            'method_code' => $this->method->code,
            'final_amount' => 1,
            'original_amount' => 1,
        ];

        $session = $this->checkoutOrchestrator->selectShippingQuote($session, $tamperedSelection);

        $selected = $session->selected_shipping_quote;
        $this->assertNotNull($selected);
        $this->assertSame(1000, $selected['original_amount']);
        $this->assertSame(1000, $selected['final_amount']);

        $session = $this->checkoutOrchestrator->reserveInventory($session);

        $ready = $this->checkoutOrchestrator->markReadyForOrder($session);

        $this->assertSame('ready_for_order', $ready->state);
        $this->assertSame(5000, $ready->totals['grand_total']);
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

    public function test_stale_shipping_quote_fingerprint_blocks_ready_for_order(): void
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

        // Manually alter stored fingerprint
        $quoteData = $session->selected_shipping_quote;
        $quoteData['fingerprint'] = 'tampered_or_stale_fingerprint_hash';
        $session->selected_shipping_quote = $quoteData;
        $session->save();

        $this->expectException(ShippingQuoteStaleException::class);
        $this->expectExceptionMessage("SHIPPING_QUOTE_STALE: Selected shipping quote [{$this->method->id}] is no longer valid");

        $this->checkoutOrchestrator->reserveInventory($session);
    }

    public function test_multi_source_fractional_split_fulfillment_and_reservation(): void
    {
        // Source A has 0.75 units, Source B has 1.00 unit. Cart requests 1.25 units.
        $this->stockItemA = StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->sourceA->id, 'product_id' => $this->fractionalProduct->id, 'on_hand' => 0.75, 'reserved' => 0]);
        $this->stockItemB = StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->sourceB->id, 'product_id' => $this->fractionalProduct->id, 'on_hand' => 1.00, 'reserved' => 0]);

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
            productId: $this->fractionalProduct->id,
            variantId: null,
            quantity: CartQuantity::fromString('1.25000000')
        ));

        $session = $this->checkoutOrchestrator->createFromCart($cart);
        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData('frac@example.com', 'F', 'User'));
        $session = $this->checkoutOrchestrator->setAddresses($session, new CheckoutAddress('F User', ['Poststrasse 1'], 'Bern', 'CH', postalCode: '3000'));
        $session = $this->checkoutOrchestrator->selectShippingQuote($session, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);

        $session = $this->checkoutOrchestrator->reserveInventory($session);

        // Assert Source A reserved exactly 0.7500 and Source B reserved exactly 0.5000
        $this->assertSame('0.7500', (string) $this->stockItemA->fresh()->reserved);
        $this->assertSame('0.5000', (string) $this->stockItemB->fresh()->reserved);
    }

    public function test_table_rate_fractional_quantity_condition_exact_matching(): void
    {
        $tableMethod = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'TABLE_SHIP',
            'name' => 'Table Shipping',
            'rate_calculator_type' => 'table_rate',
            'currency' => 'CHF',
            'base_amount' => 500,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $tableMethod->id, 'shipping_zone_id' => $this->zone->id]);

        // Increase available inventory for fractionalProduct so 1.50 and 1.75 are fulfillable
        StockItem::where('product_id', $this->fractionalProduct->id)->update(['on_hand' => 100]);

        // Rule: min_quantity = 1.50 adds 300 minor fee
        ShippingRateRule::create([
            'shipping_method_id' => $tableMethod->id,
            'name' => 'Min 1.5 Units Rule',
            'condition_type' => 'min_quantity',
            'action_type' => 'fixed_amount',
            'conditions_payload' => ['min_quantity' => '1.5000'],
            'action_payload' => ['amount' => 300],
            'priority' => 10,
        ]);

        $ctx = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user->id
        );

        // 1. Qty = 1.25 -> MUST NOT match rule (no quote returned)
        $cart1 = Cart::create([
            'tenant_id' => $this->tenant->id,
            'guest_token_hash' => hash('sha256', 'guest-cart-tr1'),
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'CHF',
            'status' => 'active',
        ]);
        $this->cartService->addLine($cart1, new CartLineItemData($this->fractionalProduct->id, null, CartQuantity::fromString('1.25000000')));
        $session1 = $this->checkoutOrchestrator->createFromCart($cart1);
        $this->checkoutOrchestrator->setCustomerData($session1, new CheckoutCustomerData('u1@example.com', 'U', '1'));
        $session1 = $this->checkoutOrchestrator->setAddresses($session1, new CheckoutAddress('U 1', ['Poststrasse 1'], 'Bern', 'CH', postalCode: '3000'));
        $rates1 = $this->checkoutOrchestrator->getShippingRates($session1);
        $tableRate1 = collect($rates1['shipping_result']->quotes)->first(fn ($q) => $q->methodId === $tableMethod->id);
        $this->assertNull($tableRate1, 'Table rate with min_quantity=1.50 must not match 1.25 quantity.');

        // 2. Qty = 1.50 -> MUST match rule (base 500 + rule 300 = 800)
        $cart2 = Cart::create([
            'tenant_id' => $this->tenant->id,
            'guest_token_hash' => hash('sha256', 'guest-cart-tr2'),
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'CHF',
            'status' => 'active',
        ]);
        $this->cartService->addLine($cart2, new CartLineItemData($this->fractionalProduct->id, null, CartQuantity::fromString('1.50000000')));
        $session2 = $this->checkoutOrchestrator->createFromCart($cart2);
        $this->checkoutOrchestrator->setCustomerData($session2, new CheckoutCustomerData('u2@example.com', 'U', '2'));
        $session2 = $this->checkoutOrchestrator->setAddresses($session2, new CheckoutAddress('U 2', ['Poststrasse 1'], 'Bern', 'CH', postalCode: '3000'));
        $rates2 = $this->checkoutOrchestrator->getShippingRates($session2);
        $tableRate2 = collect($rates2['shipping_result']->quotes)->first(fn ($q) => $q->methodId === $tableMethod->id);
        $this->assertNotNull($tableRate2, 'Table rate with min_quantity=1.50 must match 1.50 quantity.');
        $this->assertSame(800, $tableRate2->amount->getMinorAmount());

        // 3. Qty = 1.75 -> MUST match rule (base 500 + rule 300 = 800)
        $cart3 = Cart::create([
            'tenant_id' => $this->tenant->id,
            'guest_token_hash' => hash('sha256', 'guest-cart-tr3'),
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'CHF',
            'status' => 'active',
        ]);
        $this->cartService->addLine($cart3, new CartLineItemData($this->fractionalProduct->id, null, CartQuantity::fromString('1.75000000')));
        $session3 = $this->checkoutOrchestrator->createFromCart($cart3);
        $this->checkoutOrchestrator->setCustomerData($session3, new CheckoutCustomerData('u3@example.com', 'U', '3'));
        $session3 = $this->checkoutOrchestrator->setAddresses($session3, new CheckoutAddress('U 3', ['Poststrasse 1'], 'Bern', 'CH', postalCode: '3000'));
        $rates3 = $this->checkoutOrchestrator->getShippingRates($session3);
        $tableRate3 = collect($rates3['shipping_result']->quotes)->first(fn ($q) => $q->methodId === $tableMethod->id);
        $this->assertNotNull($tableRate3, 'Table rate with min_quantity=1.50 must match 1.75 quantity.');
        $this->assertSame(800, $tableRate3->amount->getMinorAmount());
    }

    public function test_package_item_composition_difference_changes_fingerprint(): void
    {
        $prod2 = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'PHYS-2',
            'name' => 'Book 2',
            'slug' => 'book-2',
            'product_type' => 'physical',
            'status' => 'active',
            'weight_kg' => 1.0,
        ]);
        $pb = PriceBook::where('tenant_id', $this->tenant->id)->firstOrFail();
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $prod2->id, 'amount_minor' => 4000, 'currency' => 'CHF', 'status' => 'active']);
        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->sourceA->id, 'product_id' => $prod2->id, 'on_hand' => 100, 'reserved' => 0]);

        $ctx = new CartContext(tenantId: $this->tenant->id, storeId: $this->store->id, marketId: $this->market->id, channelId: $this->channel->id, currency: 'CHF', userId: $this->user->id);

        // Cart A: 1 unit of Product 1 (1kg)
        $cartA = Cart::create([
            'tenant_id' => $this->tenant->id,
            'guest_token_hash' => hash('sha256', 'guest-cart-a'),
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'CHF',
            'status' => 'active',
        ]);
        $this->cartService->addLine($cartA, new CartLineItemData($this->physicalProduct->id, null, CartQuantity::fromInt(1)));
        $sessionA = $this->checkoutOrchestrator->createFromCart($cartA);
        $this->checkoutOrchestrator->setCustomerData($sessionA, new CheckoutCustomerData('a@example.com', 'A', '1'));
        $sessionA = $this->checkoutOrchestrator->setAddresses($sessionA, new CheckoutAddress('A 1', ['Poststrasse 1'], 'Bern', 'CH', postalCode: '3000'));
        $sessionA = $this->checkoutOrchestrator->selectShippingQuote($sessionA, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);

        // Cart B: 1 unit of Product 2 (1kg) -> Same total weight (1kg), same item count (1), same source, but DIFFERENT item composition
        $cartB = Cart::create([
            'tenant_id' => $this->tenant->id,
            'guest_token_hash' => hash('sha256', 'guest-cart-b'),
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'CHF',
            'status' => 'active',
        ]);
        $this->cartService->addLine($cartB, new CartLineItemData($prod2->id, null, CartQuantity::fromInt(1)));
        $sessionB = $this->checkoutOrchestrator->createFromCart($cartB);
        $this->checkoutOrchestrator->setCustomerData($sessionB, new CheckoutCustomerData('b@example.com', 'B', '1'));
        $sessionB = $this->checkoutOrchestrator->setAddresses($sessionB, new CheckoutAddress('B 1', ['Poststrasse 1'], 'Bern', 'CH', postalCode: '3000'));
        $sessionB = $this->checkoutOrchestrator->selectShippingQuote($sessionB, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);

        $this->assertNotSame(
            $sessionA->selected_shipping_quote['fingerprint'],
            $sessionB->selected_shipping_quote['fingerprint']
        );
    }

    public function test_automatic_free_shipping_promotion_full_lifecycle_and_restrictions(): void
    {
        $ctx = new CartContext(tenantId: $this->tenant->id, storeId: $this->store->id, marketId: $this->market->id, channelId: $this->channel->id, currency: 'CHF', userId: $this->user->id);
        $cart = $this->cartService->getOrCreateActiveCart($ctx);
        $this->cartService->addLine($cart, new CartLineItemData($this->physicalProduct->id, null, CartQuantity::fromInt(1)));

        $session = $this->checkoutOrchestrator->createFromCart($cart);
        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData('auto@example.com', 'Auto', 'User'));
        $session = $this->checkoutOrchestrator->setAddresses($session, new CheckoutAddress('Auto User', ['Poststrasse 1'], 'Bern', 'CH', postalCode: '3000'));

        // Case A: Before promotion, quote is paid 1000 minor
        $quotesRes = $this->shippingOrchestrator->quote($session->cart, CheckoutAddress::fromArray($session->shipping_address));
        $initialQuote = $quotesRes['shipping_result']->quotes[0];
        $this->assertSame(1000, $initialQuote->amount->getMinorAmount());
        $this->assertSame(0, $initialQuote->breakdown->promotionDiscount->getMinorAmount());

        // Select the paid quote
        $session = $this->checkoutOrchestrator->selectShippingQuote($session, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);
        $this->assertSame(1000, $session->selected_shipping_quote['final_amount']);

        // Enable automatic FreeShipping promotion
        $autoPromo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Auto Free Shipping',
            'code' => 'AUTO_FREE_SHIP',
            'status' => 'active',
            'priority' => 100,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
        ]);
        PromotionAction::create([
            'promotion_id' => $autoPromo->id,
            'action_type' => 'free_shipping',
            'parameters' => [],
        ]);

        // Case B: Existing selected paid quote becomes stale
        $thrownStale = false;
        try {
            $this->checkoutOrchestrator->reserveInventory($session);
        } catch (ShippingQuoteStaleException $e) {
            $thrownStale = true;
        }
        $this->assertTrue($thrownStale, 'Enabling automatic FreeShipping must invalidate stored paid quote.');

        // Case C: Customer re-selects quote -> final amount is 0 and promotion_discount is 1000
        $quotesResAfter = $this->shippingOrchestrator->quote($session->cart, CheckoutAddress::fromArray($session->shipping_address));
        $freeQuote = $quotesResAfter['shipping_result']->quotes[0];
        $this->assertSame(0, $freeQuote->amount->getMinorAmount());
        $this->assertSame(1000, $freeQuote->breakdown->promotionDiscount->getMinorAmount());

        $session = $this->checkoutOrchestrator->selectShippingQuote($session, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);
        $this->assertSame(0, $session->selected_shipping_quote['final_amount']);
        $this->assertSame(1000, $session->selected_shipping_quote['breakdown']['promotion_discount']);

        // Case D: Disable automatic FreeShipping -> stored free quote becomes stale, fresh quote is 1000 again
        $autoPromo->update(['status' => 'inactive']);

        $thrownStaleAfterDisable = false;
        try {
            $this->checkoutOrchestrator->reserveInventory($session);
        } catch (ShippingQuoteStaleException $e) {
            $thrownStaleAfterDisable = true;
        }
        $this->assertTrue($thrownStaleAfterDisable, 'Disabling automatic FreeShipping must invalidate stored free quote.');

        $quotesResReverted = $this->shippingOrchestrator->quote($session->cart, CheckoutAddress::fromArray($session->shipping_address));
        $revertedQuote = $quotesResReverted['shipping_result']->quotes[0];
        $this->assertSame(1000, $revertedQuote->amount->getMinorAmount());
        $this->assertSame(0, $revertedQuote->breakdown->promotionDiscount->getMinorAmount());
    }

    public function test_checkout_source_contains_no_direct_promotion_model_inspection(): void
    {
        /** @var list<string> $checkoutFiles */
        $checkoutFiles = glob(base_path('modules/Checkout/**/*.php')) ?: [];
        $this->assertNotEmpty($checkoutFiles);
        foreach ($checkoutFiles as $file) {
            $content = (string) file_get_contents($file);
            $this->assertStringNotContainsString('Promotion::find', $content, "File {$file} must not query Promotion::find");
            $this->assertStringNotContainsString("actions()->where('action_type'", $content, "File {$file} must not inspect Promotion actions directly");
            $this->assertStringNotContainsString("where('action_type', 'free_shipping')", $content, "File {$file} must not hardcode promotion action inspection");
            $this->assertStringNotContainsString('PromotionAction::', $content, "File {$file} must not access PromotionAction models directly");
        }
    }

    public function test_coupon_only_free_shipping_promotion_semantics(): void
    {
        $ctx = new CartContext(tenantId: $this->tenant->id, storeId: $this->store->id, marketId: $this->market->id, channelId: $this->channel->id, currency: 'CHF', userId: $this->user->id);
        $cart = $this->cartService->getOrCreateActiveCart($ctx);
        $this->cartService->addLine($cart, new CartLineItemData($this->physicalProduct->id, null, CartQuantity::fromInt(1)));

        // Create a Coupon-Only Free Shipping promotion
        $promo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Coupon Free Shipping',
            'code' => 'PROMO_SHIP_COUPON',
            'status' => 'active',
            'priority' => 100,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
        ]);
        PromotionAction::create([
            'promotion_id' => $promo->id,
            'action_type' => 'free_shipping',
            'parameters' => [],
        ]);
        Coupon::create([
            'tenant_id' => $this->tenant->id,
            'promotion_id' => $promo->id,
            'code' => 'FREESHIP20',
            'status' => 'active',
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
        ]);

        $dest = new CheckoutAddress('User', ['Street 1'], 'Zurich', 'CH', postalCode: '8000');

        // Test A: No coupon supplied -> shipping remains paid 1000 minor
        $quotesNoCoupon = $this->shippingOrchestrator->quote($cart, $dest);
        $this->assertSame(1000, $quotesNoCoupon['shipping_result']->quotes[0]->amount->getMinorAmount());
        $this->assertSame(0, $quotesNoCoupon['shipping_result']->quotes[0]->breakdown->promotionDiscount->getMinorAmount());

        // Test B: Wrong coupon -> shipping remains paid 1000 minor
        $cart->coupon_code = 'WRONGCODE';
        $cart->save();
        $quotesWrongCoupon = $this->shippingOrchestrator->quote($cart, $dest);
        $this->assertSame(1000, $quotesWrongCoupon['shipping_result']->quotes[0]->amount->getMinorAmount());
        $this->assertSame(0, $quotesWrongCoupon['shipping_result']->quotes[0]->breakdown->promotionDiscount->getMinorAmount());

        // Test C: Valid coupon -> shipping becomes 0 minor with 1000 discount
        $cart->coupon_code = 'FREESHIP20';
        $cart->save();
        $quotesValidCoupon = $this->shippingOrchestrator->quote($cart, $dest);
        $this->assertSame(0, $quotesValidCoupon['shipping_result']->quotes[0]->amount->getMinorAmount());
        $this->assertSame(1000, $quotesValidCoupon['shipping_result']->quotes[0]->breakdown->promotionDiscount->getMinorAmount());

        // Test D: Expired coupon -> shipping remains paid 1000 minor
        $expiredCoupon = Coupon::where('code', 'FREESHIP20')->firstOrFail();
        $expiredCoupon->update(['status' => 'inactive']);
        $quotesExpiredCoupon = $this->shippingOrchestrator->quote($cart, $dest);
        $this->assertSame(1000, $quotesExpiredCoupon['shipping_result']->quotes[0]->amount->getMinorAmount());
        $this->assertSame(0, $quotesExpiredCoupon['shipping_result']->quotes[0]->breakdown->promotionDiscount->getMinorAmount());

        // Test E: Automatic FreeShipping promotion without coupons -> shipping becomes 0 minor
        $autoPromo = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Auto Free Ship',
            'code' => 'AUTO_FREE_ALL',
            'status' => 'active',
            'priority' => 50,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
        ]);
        PromotionAction::create([
            'promotion_id' => $autoPromo->id,
            'action_type' => 'free_shipping',
            'parameters' => [],
        ]);

        $cart->coupon_code = null;
        $cart->save();
        $quotesAutoPromo = $this->shippingOrchestrator->quote($cart, $dest);
        $this->assertSame(0, $quotesAutoPromo['shipping_result']->quotes[0]->amount->getMinorAmount());
        $this->assertSame(1000, $quotesAutoPromo['shipping_result']->quotes[0]->breakdown->promotionDiscount->getMinorAmount());
    }
}
