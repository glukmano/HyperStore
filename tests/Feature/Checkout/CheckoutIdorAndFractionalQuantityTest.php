<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Context\DTOs\UserContext;
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
use Modules\Checkout\Exceptions\CheckoutAccessDeniedException;
use Modules\Checkout\Services\CheckoutOwnershipService;
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

class CheckoutIdorAndFractionalQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private Channel $channel;

    private User $user1;

    private User $user2;

    private Product $fractionalProduct;

    private StockItem $stockItem;

    private ShippingMethod $method;

    private CartServiceInterface $cartService;

    private CheckoutOrchestratorInterface $checkoutOrchestrator;

    private CheckoutOwnershipService $ownershipService;

    private ContextManager $contextManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Currency::firstOrCreate(['code' => 'CHF'], ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_active' => true]);

        $this->tenant = Tenant::create(['name' => 'IDOR Tenant', 'slug' => 'idor-tenant', 'status' => 'active']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'IDOR_S1', 'name' => 'Store 1', 'slug' => 'idor-s1', 'status' => 'active']);
        $this->market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'CH', 'name' => 'Switzerland', 'default_currency_code' => 'CHF', 'default_locale_code' => 'en', 'is_active' => true]);
        $this->channel = Channel::create(['name' => 'Web', 'handle' => 'web', 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->store->id, 'channel_id' => $this->channel->id, 'is_active' => true]);

        $this->user1 = User::factory()->create();
        $this->user2 = User::factory()->create();

        $taxClass = TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'STD_TAX', 'name' => 'Standard Tax', 'is_default' => true]);

        $this->fractionalProduct = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'FRAC-FABRIC-1',
            'name' => 'Fabric by Meter',
            'slug' => 'fabric-meter',
            'product_type' => 'custom',
            'status' => 'active',
            'weight_kg' => 0.8,
            'metadata' => ['allows_fractional_quantity' => true],
        ]);

        $wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_FRAC', 'name' => 'WH Frac', 'country_code' => 'CH', 'status' => 'active']);
        $source = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC_FRAC', 'name' => 'Source Frac', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);
        $this->stockItem = StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $source->id, 'product_id' => $this->fractionalProduct->id, 'on_hand' => 100, 'reserved' => 0]);

        $pb = PriceBook::create(['tenant_id' => $this->tenant->id, 'code' => 'STD', 'name' => 'Std', 'currency' => 'CHF', 'status' => 'active', 'priority' => 1]);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $this->fractionalProduct->id, 'amount_minor' => 4000, 'currency' => 'CHF', 'status' => 'active']); // 40.00 CHF / meter

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_ZONE', 'name' => 'CH Zone', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);
        $this->method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'STD_SHIP',
            'name' => 'Standard Shipping',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 500,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $this->method->id, 'shipping_zone_id' => $zone->id]);

        $this->cartService = app(CartServiceInterface::class);
        $this->checkoutOrchestrator = app(CheckoutOrchestratorInterface::class);
        $this->ownershipService = app(CheckoutOwnershipService::class);
        $this->contextManager = app(ContextManager::class);
    }

    public function test_same_tenant_idor_protection_for_checkout(): void
    {
        $ctx = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user1->id
        );
        $cart = $this->cartService->getOrCreateActiveCart($ctx);
        $this->cartService->addLine($cart, new CartLineItemData(
            productId: $this->fractionalProduct->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1)
        ));

        $session = $this->checkoutOrchestrator->createFromCart($cart);

        // Switch request-scoped context to User 2 within same Tenant
        $this->contextManager->setTenant(TenantContext::from($this->tenant->id));
        $this->contextManager->setUser(UserContext::from($this->user2->id, 'u2@example.com'));

        $this->expectException(CheckoutAccessDeniedException::class);
        $this->ownershipService->verifyOwnership($session);
    }

    public function test_fractional_quantity_end_to_end_exact_financial_and_reservation_calculation(): void
    {
        $ctx = new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->user1->id
        );
        $cart = $this->cartService->getOrCreateActiveCart($ctx);

        // Add 1.25 units of 40.00 CHF product (4000 minor * 1.25 = 5000 minor)
        $qty = CartQuantity::fromString('1.25000000');
        $this->cartService->addLine($cart, new CartLineItemData(
            productId: $this->fractionalProduct->id,
            variantId: null,
            quantity: $qty
        ));

        $session = $this->checkoutOrchestrator->createFromCart($cart);
        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData('frac@example.com', 'Frac', 'User'));
        $session = $this->checkoutOrchestrator->setAddresses($session, new CheckoutAddress('Frac User', ['Street 1'], 'Zurich', 'CH', postalCode: '8000'));
        $session = $this->checkoutOrchestrator->selectShippingQuote($session, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);
        $session = $this->checkoutOrchestrator->reserveInventory($session);

        // Assert that stock reservation preserved exact fractional quantity (1.2500)
        $this->assertSame('1.2500', (string) $this->stockItem->fresh()->reserved);

        $ready = $this->checkoutOrchestrator->markReadyForOrder($session);

        $this->assertSame('ready_for_order', $ready->state);
        $this->assertCount(1, $ready->lines);
        $this->assertSame('1.25', (string) $ready->lines[0]['quantity']);

        // Assert exact merchandise subtotal is 5000 minor (50.00 CHF), NOT truncated to 4000
        $this->assertSame(5000, $ready->totals['merchandise_subtotal']);
        // Grand total: 5000 subtotal + 500 shipping = 5500 minor (55.00 CHF)
        $this->assertSame(5500, $ready->totals['grand_total']);
    }
}
