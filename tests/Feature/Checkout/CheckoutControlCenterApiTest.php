<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Currency;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\CartCheckoutPermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CheckoutControlCenterApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private Channel $channel;

    private User $adminUser;

    private User $unauthorizedUser;

    private Product $product;

    private InventorySource $source;

    private StockItem $stockItem;

    private ShippingMethod $method;

    private CartServiceInterface $cartService;

    private CheckoutOrchestratorInterface $checkoutOrchestrator;

    private ContextManager $contextManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(CartCheckoutPermissionSeeder::class);
        Currency::firstOrCreate(['code' => 'CHF'], ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_active' => true]);

        $this->tenant = Tenant::create(['name' => 'CC Tenant', 'slug' => 'cc-tenant', 'status' => 'active']);
        TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'STD_TAX', 'name' => 'Standard Tax', 'is_default' => true]);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'CC_S1', 'name' => 'Store 1', 'slug' => 'cc-s1', 'status' => 'active']);
        $this->market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'CH', 'name' => 'Switzerland', 'default_currency_code' => 'CHF', 'default_locale_code' => 'en', 'is_active' => true]);
        $this->channel = Channel::create(['name' => 'Web', 'handle' => 'web', 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->store->id, 'channel_id' => $this->channel->id, 'is_active' => true]);

        $this->adminUser = User::factory()->create();
        $this->unauthorizedUser = User::factory()->create();

        $role = Role::findByName('super_admin', 'web');
        $this->adminUser->assignRole($role);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'CC-PROD-1',
            'name' => 'CC Product',
            'slug' => 'cc-product',
            'product_type' => 'physical',
            'status' => 'active',
            'weight_kg' => 1.0,
        ]);

        $wh = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_CC', 'name' => 'WH CC', 'country_code' => 'CH', 'status' => 'active']);
        $this->source = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $wh->id, 'code' => 'SRC_CC', 'name' => 'Source CC', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);
        $this->stockItem = StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->source->id, 'product_id' => $this->product->id, 'on_hand' => 10, 'reserved' => 0]);

        $pb = PriceBook::create(['tenant_id' => $this->tenant->id, 'code' => 'STD', 'name' => 'Std', 'currency' => 'CHF', 'status' => 'active', 'priority' => 1]);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $this->product->id, 'amount_minor' => 1000, 'currency' => 'CHF', 'status' => 'active']);

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
        $this->contextManager = app(ContextManager::class);
    }

    public function test_control_center_checkout_sessions_rbac_and_tenant_isolation(): void
    {
        $this->contextManager->setTenant(TenantContext::from($this->tenant->id));

        $cart = $this->cartService->getOrCreateActiveCart(new CartContext(tenantId: $this->tenant->id, storeId: $this->store->id, marketId: $this->market->id, channelId: $this->channel->id, currency: 'CHF', userId: $this->adminUser->id));
        $this->cartService->addLine($cart, new CartLineItemData($this->product->id, null, CartQuantity::fromInt(1)));
        $session = $this->checkoutOrchestrator->createFromCart($cart);

        // 1. Unauthorized user cannot access Control Center checkout sessions
        Sanctum::actingAs($this->unauthorizedUser, ['*']);
        $resp = $this->getJson('/api/v1/control-center/checkout/sessions');
        $resp->assertStatus(403);

        // 2. Admin with checkout.inspect permission can inspect
        Sanctum::actingAs($this->adminUser, ['*']);
        $resp = $this->getJson('/api/v1/control-center/checkout/sessions');
        $resp->assertStatus(200);
        $resp->assertJsonFragment(['id' => $session->id]);

        $respDetail = $this->getJson("/api/v1/control-center/checkout/sessions/{$session->id}");
        $respDetail->assertStatus(200);
        $respDetail->assertJsonFragment(['id' => $session->id]);
    }

    public function test_control_center_manual_reservation_release(): void
    {
        $this->contextManager->setTenant(TenantContext::from($this->tenant->id));

        $cart = $this->cartService->getOrCreateActiveCart(new CartContext(tenantId: $this->tenant->id, storeId: $this->store->id, marketId: $this->market->id, channelId: $this->channel->id, currency: 'CHF', userId: $this->adminUser->id));
        $this->cartService->addLine($cart, new CartLineItemData($this->product->id, null, CartQuantity::fromInt(2)));
        $session = $this->checkoutOrchestrator->createFromCart($cart);
        $this->checkoutOrchestrator->setCustomerData($session, new CheckoutCustomerData('admin@example.com', 'Admin', 'User'));
        $this->checkoutOrchestrator->setAddresses($session, new CheckoutAddress('Admin User', ['Street 1'], 'Zurich', 'CH', postalCode: '8000'));
        $this->checkoutOrchestrator->selectShippingQuote($session, ['method_id' => $this->method->id, 'method_code' => $this->method->code]);
        $session = $this->checkoutOrchestrator->reserveInventory($session);

        $this->assertSame('2.0000', (string) $this->stockItem->fresh()->reserved);

        // Release reservations via Control Center endpoint
        Sanctum::actingAs($this->adminUser, ['*']);
        $resp = $this->postJson("/api/v1/control-center/checkout/sessions/{$session->id}/release-reservations");
        $resp->assertStatus(200);

        $this->assertSame('0.0000', (string) $this->stockItem->fresh()->reserved);
        $this->assertNull($session->fresh()->reservation_references);
    }
}
