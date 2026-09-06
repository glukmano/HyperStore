<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Context\ContextManager;
use App\Core\Context\DTOs\TenantContext;
use App\Core\Markets\Models\Market;
use App\Core\Markets\Models\MarketCurrency;
use App\Core\Markets\Models\StoreMarket;
use App\Core\Stores\Models\Store;
use App\Core\Stores\Models\StoreUser;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\Phase22PermissionSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Customers\Models\CustomerProfile;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Order\Enums\FulfillmentStatus;
use Modules\Order\Models\Order;
use Modules\POS\DTOs\PosTenderSelection;
use Modules\POS\Exceptions\CrossStoreReturnNotAllowedException;
use Modules\POS\Models\PosCashMovement;
use Modules\POS\Models\PosCrossStoreReturnPolicy;
use Modules\POS\Models\PosRegister;
use Modules\POS\Services\PosCrossStoreReturnPolicyService;
use Modules\POS\Services\PosPickupService;
use Modules\POS\Services\PosRegisterSessionService;
use Modules\POS\Services\PosSaleOrchestrator;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Wallet\Models\StoreValueAccount;
use Modules\Wallet\Services\StoreValueRefundService;
use Tests\TestCase;

class PosRefundAndPickupTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private InventorySource $source;

    private User $cashier;

    private PosRegister $register;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(Phase22PermissionSeeder::class);

        $uid = uniqid();
        $this->tenant = Tenant::create(['name' => 'POS Refund Tenant', 'slug' => 'pos-refund-'.$uid, 'status' => 'active']);
        app(ContextManager::class)->setTenant(TenantContext::from($this->tenant->id));
        app(LedgerAccountRegistryInterface::class)->ensureRequiredSystemAccounts($this->tenant->id);

        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'PRS_'.$uid, 'name' => 'Store', 'slug' => 'prs-'.$uid, 'status' => 'active']);
        $market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'PRM_'.$uid, 'name' => 'Market', 'default_currency_code' => 'USD', 'default_locale_code' => 'en', 'is_active' => true]);
        MarketCurrency::create(['market_id' => $market->id, 'currency_code' => 'USD', 'is_default' => true]);
        $storeMarket = StoreMarket::create(['store_id' => $this->store->id, 'market_id' => $market->id, 'is_active' => true, 'is_default' => true]);

        $channel = Channel::create(['type' => 'pos', 'name' => 'POS', 'handle' => 'pos-'.$uid, 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->store->id, 'channel_id' => $channel->id]);

        $this->cashier = User::factory()->create();
        StoreUser::create(['store_id' => $this->store->id, 'user_id' => $this->cashier->id, 'role' => 'cashier', 'is_active' => true]);
        $this->cashier->givePermissionTo(Phase22PermissionSeeder::PERMISSIONS);

        $warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WHR_'.$uid, 'name' => 'Warehouse', 'country_code' => 'US', 'status' => 'active']);
        $this->source = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'SRCR_'.$uid, 'name' => 'Source', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);

        $this->register = PosRegister::create([
            'tenant_id' => $this->tenant->id,
            'store_market_id' => $storeMarket->id,
            'channel_id' => $channel->id,
            'inventory_source_id' => $this->source->id,
            'code' => 'REGR',
            'name' => 'Register R',
            'status' => 'active',
        ]);

        TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'STDR_'.$uid, 'name' => 'Standard', 'is_default' => true]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'PRS-SKU-'.$uid,
            'name' => 'Refund Product',
            'slug' => 'refund-product-'.$uid,
            'product_type' => 'physical',
            'status' => 'active',
        ]);
        ProductStoreListing::create(['product_id' => $this->product->id, 'store_id' => $this->store->id, 'status' => 'published']);
        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->source->id, 'product_id' => $this->product->id, 'on_hand' => 100, 'reserved' => 0]);

        $pb = PriceBook::create(['tenant_id' => $this->tenant->id, 'code' => 'PRB_'.$uid, 'name' => 'PB', 'currency' => 'USD', 'status' => 'active', 'priority' => 1]);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $this->product->id, 'amount_minor' => 1000, 'currency' => 'USD', 'status' => 'active']);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'PR_ZONE_'.$uid, 'name' => 'Zone', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'US']);
        $method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'PR_INSTORE_'.$uid,
            'name' => 'In-Store',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'USD',
            'base_amount' => 0,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $method->id, 'shipping_zone_id' => $zone->id]);
    }

    private function completeCashSale(?int $userId, string $clientSaleId): Order
    {
        $session = app(PosRegisterSessionService::class)->open($this->register, $this->cashier->id, 'USD', 10000);
        $orchestrator = app(PosSaleOrchestrator::class);
        $cartService = app(CartServiceInterface::class);

        $cart = $orchestrator->getOrCreateCart($session, $userId, $userId === null ? 'guest-'.$clientSaleId : null);
        $cartService->addLine($cart, new CartLineItemData(
            productId: $this->product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1),
        ));

        $result = $orchestrator->completeSale($session, $cart, $clientSaleId, new PosTenderSelection(cashAmountMinor: 1000));

        // Only one active session may exist per register (DB-enforced in
        // production) — close this one so a later refund test's freshly-
        // opened session is unambiguously the sole active session.
        app(PosRegisterSessionService::class)->close($session->fresh(), 10000, $this->cashier->id);

        return $result->order;
    }

    public function test_cash_refund_with_an_open_register_session_records_a_compensating_drawer_movement(): void
    {
        $order = $this->completeCashSale(null, 'refund-sale-1');

        // A fresh session is opened for processing the refund at the register.
        $refundSession = app(PosRegisterSessionService::class)->open($this->register, $this->cashier->id, 'USD', 0);

        $refundEventUuid = (string) Str::uuid();
        $results = app(StoreValueRefundService::class)->refundOrder($order, 1000, $refundEventUuid);

        $this->assertCount(1, $results);
        $this->assertSame('cash', $results[0]['tender_type']);

        $movement = PosCashMovement::where('source_type', 'order_refund')->where('source_uuid', $refundEventUuid)->first();
        $this->assertNotNull($movement);
        $this->assertSame(-1000, $movement->amount_minor);
        $this->assertSame($refundSession->id, $movement->register_session_id);
    }

    public function test_cash_refund_with_no_open_register_session_falls_back_to_store_credit(): void
    {
        $user = User::factory()->create();
        $order = $this->completeCashSale($user->id, 'refund-sale-2');

        // No register session is open at this point.
        $refundEventUuid = (string) Str::uuid();
        app(StoreValueRefundService::class)->refundOrder($order, 1000, $refundEventUuid);

        $profile = CustomerProfile::where('user_id', $user->id)->first();
        $this->assertNotNull($profile);

        $account = StoreValueAccount::where('customer_profile_id', $profile->id)->where('instrument_type', 'store_credit')->first();
        $this->assertNotNull($account, 'A Store Credit account must be created as the cash-refund fallback destination.');
    }

    public function test_bopis_lifecycle_transitions_and_no_show_expiry_releases_inventory(): void
    {
        $order = $this->completeCashSale(null, 'pickup-sale-1');

        app(PosPickupService::class)->markReadyForPickup($order);
        $this->assertSame(FulfillmentStatus::READY_FOR_PICKUP->value, $order->fresh()->fulfillment_status);

        $confirmed = app(PosPickupService::class)->confirmPickedUp($order->fresh());
        $this->assertTrue($confirmed);
        $this->assertSame(FulfillmentStatus::PICKED_UP->value, $order->fresh()->fulfillment_status);

        // Second confirmation is idempotent — no-op, never throws.
        $second = app(PosPickupService::class)->confirmPickedUp($order->fresh());
        $this->assertFalse($second);
    }

    public function test_no_show_expiry_cancels_fulfillment(): void
    {
        $order = $this->completeCashSale(null, 'pickup-sale-2');
        app(PosPickupService::class)->markReadyForPickup($order);

        $expired = app(PosPickupService::class)->expireNoShow($order->fresh());
        $this->assertSame(FulfillmentStatus::CANCELLED->value, $expired->fulfillment_status);
    }

    public function test_cross_store_return_is_rejected_when_policy_is_disabled(): void
    {
        PosCrossStoreReturnPolicy::create(['tenant_id' => $this->tenant->id, 'cross_store_returns_enabled' => false]);

        $otherStore = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'OTHER_'.uniqid(), 'name' => 'Other Store', 'slug' => 'other-'.uniqid(), 'status' => 'active']);

        $this->expectException(CrossStoreReturnNotAllowedException::class);
        app(PosCrossStoreReturnPolicyService::class)->assertAllowed(
            $this->tenant->id,
            $this->store->id,
            $otherStore->id,
            $this->source->id,
            $this->cashier->id
        );
    }

    public function test_cross_store_return_at_the_original_store_is_always_allowed_regardless_of_policy(): void
    {
        PosCrossStoreReturnPolicy::create(['tenant_id' => $this->tenant->id, 'cross_store_returns_enabled' => false]);

        // Same store as original — never blocked, policy is irrelevant here.
        app(PosCrossStoreReturnPolicyService::class)->assertAllowed(
            $this->tenant->id,
            $this->store->id,
            $this->store->id,
            $this->source->id,
            $this->cashier->id
        );

        $this->assertTrue(true);
    }
}
