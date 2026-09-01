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
use Modules\Checkout\Exceptions\CheckoutExpiredException;
use Modules\Inventory\Models\InventoryReservation;
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
use RuntimeException;
use Tests\TestCase;

class CheckoutReservationAndIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private Channel $channel;

    private User $user;

    private Product $product;

    private InventorySource $sourceA;

    private InventorySource $sourceB;

    private StockItem $stockItemA;

    private StockItem $stockItemB;

    private ShippingZone $zone;

    private ShippingMethod $method;

    private CartServiceInterface $cartService;

    private CheckoutOrchestratorInterface $checkoutOrchestrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Currency::firstOrCreate(['code' => 'CHF'], ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_active' => true]);

        $this->tenant = Tenant::create(['name' => 'Res Tenant', 'slug' => 'res-tenant', 'status' => 'active']);
        TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'STD_TAX', 'name' => 'Standard Tax', 'is_default' => true]);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'RES_S1', 'name' => 'Store 1', 'slug' => 'res-s1', 'status' => 'active']);
        $this->market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'CH', 'name' => 'Switzerland', 'default_currency_code' => 'CHF', 'default_locale_code' => 'en', 'is_active' => true]);
        $this->channel = Channel::create(['name' => 'Web', 'handle' => 'web', 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->store->id, 'channel_id' => $this->channel->id, 'is_active' => true]);

        $this->user = User::factory()->create();

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'WIDGET-1',
            'name' => 'Widget',
            'slug' => 'widget',
            'product_type' => 'physical',
            'status' => 'active',
            'weight_kg' => 1.0,
        ]);

        $wh1 = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_A', 'name' => 'WH A', 'country_code' => 'CH', 'status' => 'active']);
        $wh2 = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_B', 'name' => 'WH B', 'country_code' => 'CH', 'status' => 'active']);

        $this->sourceA = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh1->id, 'code' => 'SRC_A', 'name' => 'Warehouse A', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 20]);
        $this->sourceB = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh2->id, 'code' => 'SRC_B', 'name' => 'Warehouse B', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);

        $pb = PriceBook::create(['tenant_id' => $this->tenant->id, 'code' => 'STD', 'name' => 'Std', 'currency' => 'CHF', 'status' => 'active', 'priority' => 1]);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $this->product->id, 'amount_minor' => 1000, 'currency' => 'CHF', 'status' => 'active']);

        $this->zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_ZONE', 'name' => 'CH Zone', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $this->zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);
        $this->method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'FLAT',
            'name' => 'Flat Rate',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 500,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $this->method->id, 'shipping_zone_id' => $this->zone->id]);

        $this->cartService = app(CartServiceInterface::class);
        $this->checkoutOrchestrator = app(CheckoutOrchestratorInterface::class);
    }

    public function test_multi_source_reservation_preserves_allocations_and_release_on_cancel(): void
    {
        // Source A has 6 units, Source B has 4 units -> total requested 10 units
        $this->stockItemA = StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->sourceA->id, 'product_id' => $this->product->id, 'on_hand' => 6, 'reserved' => 0]);
        $this->stockItemB = StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->sourceB->id, 'product_id' => $this->product->id, 'on_hand' => 4, 'reserved' => 0]);

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
            productId: $this->product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(10)
        ));

        $session = $this->checkoutOrchestrator->createFromCart($cart);
        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData('test@example.com', 'Test', 'User'));
        $this->checkoutOrchestrator->setAddresses($session, new CheckoutAddress('Test User', ['Street 1'], 'Zurich', 'CH', postalCode: '8000'));
        $this->checkoutOrchestrator->selectShippingQuote($session, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);

        $session = $this->checkoutOrchestrator->reserveInventory($session);

        $this->assertCount(2, $session->reservation_references);
        $this->assertSame('inventory_reserved', $session->state);
        $this->assertSame('6.0000', (string) $this->stockItemA->fresh()->reserved);
        $this->assertSame('4.0000', (string) $this->stockItemB->fresh()->reserved);

        // Cancel checkout session -> releases all reservations
        $this->checkoutOrchestrator->cancel($session);

        $this->assertSame('0.0000', (string) $this->stockItemA->fresh()->reserved);
        $this->assertSame('0.0000', (string) $this->stockItemB->fresh()->reserved);
        $this->assertNull($session->fresh()->reservation_references);
        $this->assertSame('cancelled', $session->fresh()->state);
    }

    public function test_atomic_rollback_on_partial_reservation_failure_leaves_zero_orphans(): void
    {
        // Source A has 6 units, Source B has only 2 units (requested 10) -> reservation fails
        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->sourceA->id, 'product_id' => $this->product->id, 'on_hand' => 6, 'reserved' => 0]);
        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->sourceB->id, 'product_id' => $this->product->id, 'on_hand' => 2, 'reserved' => 0]);

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
            productId: $this->product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(10)
        ));

        $session = $this->checkoutOrchestrator->createFromCart($cart);
        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData('test@example.com', 'Test', 'User'));
        $this->checkoutOrchestrator->setAddresses($session, new CheckoutAddress('Test User', ['Street 1'], 'Zurich', 'CH', postalCode: '8000'));
        $this->checkoutOrchestrator->selectShippingQuote($session, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);

        try {
            $this->checkoutOrchestrator->reserveInventory($session);
            $this->fail('Expected RuntimeException on insufficient inventory.');
        } catch (RuntimeException $e) {
            // Success: Exception thrown
        }

        // Verify zero reservations persisted in DB due to atomic rollback
        $this->assertSame(0, InventoryReservation::where('tenant_id', $this->tenant->id)->count());
        $this->assertNull($session->fresh()->reservation_references);
    }

    public function test_ready_for_order_idempotent_replay(): void
    {
        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->sourceA->id, 'product_id' => $this->product->id, 'on_hand' => 10, 'reserved' => 0]);

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
            productId: $this->product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1)
        ));

        $session = $this->checkoutOrchestrator->createFromCart($cart);
        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData('test@example.com', 'Test', 'User'));
        $this->checkoutOrchestrator->setAddresses($session, new CheckoutAddress('Test User', ['Street 1'], 'Zurich', 'CH', postalCode: '8000'));
        $this->checkoutOrchestrator->selectShippingQuote($session, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);
        $session = $this->checkoutOrchestrator->reserveInventory($session);

        $idempKey = 'key-ready-12345';
        $res1 = $this->checkoutOrchestrator->markReadyForOrder($session, $idempKey);
        $res2 = $this->checkoutOrchestrator->markReadyForOrder($session, $idempKey);

        $this->assertSame($res1->checkoutSessionId, $res2->checkoutSessionId);
        $this->assertSame($res1->totals, $res2->totals);
        $this->assertSame($res1->finalizedAt->toIso8601String(), $res2->finalizedAt->toIso8601String());
    }

    public function test_direct_reserve_on_already_expired_checkout_transitions_durably_to_expired(): void
    {
        $ctx = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user->id
        );
        $this->wh1 = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH1', 'name' => 'WH 1', 'country_code' => 'CH', 'status' => 'active']);
        $this->sourceA = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $this->wh1->id, 'code' => 'SRC1', 'name' => 'Source A', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);
        $this->stockItemA = StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->sourceA->id, 'product_id' => $this->product->id, 'on_hand' => 10, 'reserved' => 0]);

        $cart = $this->cartService->getOrCreateActiveCart($ctx);
        $this->cartService->addLine($cart, new CartLineItemData(
            productId: $this->product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(2)
        ));

        $session = $this->checkoutOrchestrator->createFromCart($cart);
        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData('u@example.com', 'U', '1'));
        $session = $this->checkoutOrchestrator->setAddresses($session, new CheckoutAddress('U 1', ['Street 1'], 'Zurich', 'CH', postalCode: '8000'));
        $session = $this->checkoutOrchestrator->selectShippingQuote($session, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);

        // Manually set expiration in the past
        $session->expires_at = now()->subMinute();
        $session->save();

        try {
            $this->checkoutOrchestrator->reserveInventory($session);
            $this->fail('Expected CheckoutExpiredException was not thrown.');
        } catch (CheckoutExpiredException $e) {
            $this->assertStringContainsString('CHECKOUT_EXPIRED', $e->getMessage());
        }

        $session->refresh();
        $this->assertSame('expired', $session->state);
        $this->assertSame('0.0000', (string) $this->stockItemA->fresh()->reserved);
        $this->assertNull($session->reservation_references);
    }

    public function test_direct_recalculate_on_already_expired_checkout_transitions_durably_to_expired(): void
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
            productId: $this->product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1)
        ));

        $session = $this->checkoutOrchestrator->createFromCart($cart);
        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData('u@example.com', 'U', '1'));

        $session->expires_at = now()->subMinute();
        $session->save();

        $this->expectException(CheckoutExpiredException::class);
        $this->checkoutOrchestrator->recalculate($session);
    }

    public function test_direct_ready_on_already_expired_checkout_transitions_durably_to_expired(): void
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
            productId: $this->product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1)
        ));

        $session = $this->checkoutOrchestrator->createFromCart($cart);
        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData('u@example.com', 'U', '1'));

        $session->expires_at = now()->subMinute();
        $session->save();

        $this->expectException(CheckoutExpiredException::class);
        $this->checkoutOrchestrator->markReadyForOrder($session);
    }
}
