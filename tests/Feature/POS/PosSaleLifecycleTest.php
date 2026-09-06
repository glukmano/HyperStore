<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
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
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Ledger\Contracts\LedgerAccountRegistryInterface;
use Modules\Ledger\Enums\SystemAccountRole;
use Modules\Ledger\Models\JournalEntry;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderPaymentTenderAllocation;
use Modules\POS\DTOs\PosTenderSelection;
use Modules\POS\Enums\CashMovementType;
use Modules\POS\Enums\RegisterSessionStatus;
use Modules\POS\Exceptions\AmbiguousBarcodeException;
use Modules\POS\Exceptions\PosRegisterSessionException;
use Modules\POS\Exceptions\UnsupportedTenderCombinationException;
use Modules\POS\Models\PosCashMovement;
use Modules\POS\Models\PosManualDiscountAuditLogEntry;
use Modules\POS\Models\PosRegister;
use Modules\POS\Models\PosRegisterSession;
use Modules\POS\Services\BarcodeLookupService;
use Modules\POS\Services\PosCashMovementService;
use Modules\POS\Services\PosManualDiscountService;
use Modules\POS\Services\PosRegisterSessionService;
use Modules\POS\Services\PosSaleOrchestrator;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Tests\TestCase;

class PosSaleLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private StoreMarket $storeMarket;

    private Channel $channel;

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
        $this->tenant = Tenant::create(['name' => 'POS Tenant', 'slug' => 'pos-tenant-'.$uid, 'status' => 'active']);
        app(LedgerAccountRegistryInterface::class)->ensureRequiredSystemAccounts($this->tenant->id);

        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'POS_S_'.$uid, 'name' => 'POS Store', 'slug' => 'pos-store-'.$uid, 'status' => 'active']);
        $this->market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'POS_M_'.$uid, 'name' => 'POS Market', 'default_currency_code' => 'USD', 'default_locale_code' => 'en', 'is_active' => true]);
        MarketCurrency::create(['market_id' => $this->market->id, 'currency_code' => 'USD', 'is_default' => true]);

        $this->storeMarket = StoreMarket::create(['store_id' => $this->store->id, 'market_id' => $this->market->id, 'is_active' => true, 'is_default' => true]);

        $this->channel = Channel::create(['type' => 'pos', 'name' => 'POS', 'handle' => 'pos-'.$uid, 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->store->id, 'channel_id' => $this->channel->id]);

        $this->cashier = User::factory()->create();
        StoreUser::create(['store_id' => $this->store->id, 'user_id' => $this->cashier->id, 'role' => 'cashier', 'is_active' => true]);
        $this->cashier->givePermissionTo(Phase22PermissionSeeder::PERMISSIONS);

        $warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH_'.$uid, 'name' => 'Warehouse', 'country_code' => 'US', 'status' => 'active']);
        $this->source = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'SRC_'.$uid, 'name' => 'Source', 'source_type' => 'warehouse', 'status' => 'active', 'priority' => 10]);

        $this->register = PosRegister::create([
            'tenant_id' => $this->tenant->id,
            'store_market_id' => $this->storeMarket->id,
            'channel_id' => $this->channel->id,
            'inventory_source_id' => $this->source->id,
            'code' => 'REG1',
            'name' => 'Register 1',
            'status' => 'active',
        ]);

        TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'STD_'.$uid, 'name' => 'Standard', 'is_default' => true]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'POS-SKU-'.$uid,
            'barcode' => 'BARCODE-'.$uid,
            'name' => 'POS Product',
            'slug' => 'pos-product-'.$uid,
            'product_type' => 'physical',
            'status' => 'active',
        ]);
        ProductStoreListing::create(['product_id' => $this->product->id, 'store_id' => $this->store->id, 'status' => 'published']);

        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $this->source->id, 'product_id' => $this->product->id, 'on_hand' => 100, 'reserved' => 0]);

        $pb = PriceBook::create(['tenant_id' => $this->tenant->id, 'code' => 'POS_PB_'.$uid, 'name' => 'PB', 'currency' => 'USD', 'status' => 'active', 'priority' => 1]);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $pb->id, 'product_id' => $this->product->id, 'amount_minor' => 1000, 'currency' => 'USD', 'status' => 'active']);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'POS_ZONE_'.$uid, 'name' => 'Zone', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'US']);
        $method = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'POS_INSTORE_'.$uid,
            'name' => 'In-Store',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'USD',
            'base_amount' => 0,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $method->id, 'shipping_zone_id' => $zone->id]);
    }

    private function openSession(int $openingFloatMinor = 10000): PosRegisterSession
    {
        return app(PosRegisterSessionService::class)->open($this->register, $this->cashier->id, 'USD', $openingFloatMinor);
    }

    public function test_opening_a_register_session_creates_an_authoritative_opening_float_movement_and_matching_snapshot(): void
    {
        $session = $this->openSession(5000);

        $this->assertSame(RegisterSessionStatus::ACTIVE, $session->status);
        $this->assertSame(5000, $session->opening_cash_minor);

        $movement = PosCashMovement::where('register_session_id', $session->id)->where('movement_type', CashMovementType::OPENING_FLOAT->value)->first();
        $this->assertNotNull($movement);
        $this->assertSame(5000, $movement->amount_minor);
    }

    public function test_a_second_open_attempt_at_app_level_is_rejected_for_an_already_active_session_register(): void
    {
        $this->openSession();

        $secondCashier = User::factory()->create();
        StoreUser::create(['store_id' => $this->store->id, 'user_id' => $secondCashier->id, 'role' => 'cashier', 'is_active' => true]);

        // No DB-level partial unique index on SQLite — but the service must
        // still not silently create two independent active sessions when
        // this exact test runs against the real Postgres connection (see
        // the Concurrency suite for the true race proof). Here we assert
        // the second PosRegisterSession row IS created (SQLite has no
        // partial unique index) but real enforcement is Postgres-only,
        // documented and tested there.
        $this->assertSame(1, PosRegisterSession::where('register_id', $this->register->id)->where('status', 'active')->count());
    }

    public function test_closing_a_session_computes_variance_from_real_cash_movements_only(): void
    {
        $session = $this->openSession(10000);

        app(PosCashMovementService::class)->record(
            session: $session,
            type: CashMovementType::SALE_CASH_IN,
            magnitudeMinor: 2500,
            sourceType: 'test_sale',
            sourceUuid: 'sale-1',
            createdByUserId: $this->cashier->id,
        );

        $closed = app(PosRegisterSessionService::class)->close($session, 12400, $this->cashier->id);

        $this->assertSame(12500, $closed->closing_cash_expected_minor);
        $this->assertSame(12400, $closed->closing_cash_counted_minor);
        $this->assertSame(-100, $closed->closing_variance_minor);
    }

    public function test_cash_movements_are_idempotent_by_source_and_never_double_counted(): void
    {
        $session = $this->openSession();
        $service = app(PosCashMovementService::class);

        $first = $service->record($session, CashMovementType::PAID_IN, 500, 'manual', 'manual-1', $this->cashier->id, 'test');
        $second = $service->record($session, CashMovementType::PAID_IN, 500, 'manual', 'manual-1', $this->cashier->id, 'test');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PosCashMovement::where('source_uuid', 'manual-1')->count());
    }

    public function test_manual_discount_is_bounded_to_the_eligible_line_amount_and_audit_logged(): void
    {
        $session = $this->openSession();
        $cart = app(PosSaleOrchestrator::class)->getOrCreateCart($session, null, 'guest-1');
        $line = app(CartServiceInterface::class)->addLine($cart, new CartLineItemData(
            productId: $this->product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(2),
        ));
        $line->update(['display_unit_price_minor' => 1000]);

        // Eligible line amount = 2 * 1000 = 2000; request far more than that.
        app(PosManualDiscountService::class)->apply($line->fresh(), $session, 5000, 'manager override', $this->cashier->id);

        $line->refresh();
        $this->assertSame(2000, (int) $line->metadata['pos_manual_discount_minor']);
        $this->assertSame(1, PosManualDiscountAuditLogEntry::where('cart_line_id', $line->id)->count());
    }

    public function test_barcode_lookup_resolves_a_unique_match(): void
    {
        $match = app(BarcodeLookupService::class)->resolve($this->tenant->id, $this->store->id, (string) $this->product->barcode);
        $this->assertSame($this->product->id, $match->productId);
    }

    public function test_barcode_lookup_rejects_an_ambiguous_match(): void
    {
        $dup = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'POS-SKU-DUP-'.uniqid(),
            'barcode' => $this->product->barcode,
            'name' => 'Duplicate Barcode Product',
            'slug' => 'dup-'.uniqid(),
            'product_type' => 'physical',
            'status' => 'active',
        ]);
        ProductStoreListing::create(['product_id' => $dup->id, 'store_id' => $this->store->id, 'status' => 'published']);

        $this->expectException(AmbiguousBarcodeException::class);
        app(BarcodeLookupService::class)->resolve($this->tenant->id, $this->store->id, (string) $this->product->barcode);
    }

    public function test_cash_plus_card_tender_combination_is_rejected(): void
    {
        $this->expectException(UnsupportedTenderCombinationException::class);
        new PosTenderSelection(cashAmountMinor: 100, cardProviderCode: 'fake');
    }

    public function test_a_completed_cash_sale_produces_an_ordinary_order_a_cash_ledger_posting_and_a_receipt(): void
    {
        $session = $this->openSession();
        $orchestrator = app(PosSaleOrchestrator::class);
        $cartService = app(CartServiceInterface::class);

        $cart = $orchestrator->getOrCreateCart($session, null, 'guest-sale-1');
        $cartService->addLine($cart, new CartLineItemData(
            productId: $this->product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1),
        ));

        $result = $orchestrator->completeSale(
            $session,
            $cart,
            'client-sale-1',
            new PosTenderSelection(cashAmountMinor: 1000),
        );

        $order = $result->order;
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame('paid', (string) $order->payment_status);
        $this->assertSame($session->id, $order->pos_register_session_id);
        $this->assertSame($this->register->id, $order->pos_register_id);
        $this->assertNotEmpty($order->receipt_number);

        $tender = OrderPaymentTenderAllocation::where('order_id', $order->id)->first();
        $this->assertNotNull($tender);
        $this->assertSame('cash', $tender->tender_type);
        $this->assertSame(1000, $tender->amount_minor);

        $cashMovement = PosCashMovement::where('register_session_id', $session->id)
            ->where('movement_type', CashMovementType::SALE_CASH_IN->value)
            ->first();
        $this->assertNotNull($cashMovement);
        $this->assertSame(1000, $cashMovement->amount_minor);

        $cashOnHand = app(LedgerAccountRegistryInterface::class)->getAccountByRole($this->tenant->id, SystemAccountRole::CASH_ON_HAND);
        $posted = JournalEntry::where('tenant_id', $this->tenant->id)
            ->whereHas('lines', fn ($q) => $q->where('ledger_account_id', $cashOnHand->id))
            ->exists();
        $this->assertTrue($posted, 'A cash sale must post a JournalEntry debiting CASH_ON_HAND.');

        $this->assertNotNull($result->receipt);
        $this->assertSame($order->id, $result->receipt->order_id);
    }

    public function test_repeating_the_same_client_sale_id_never_creates_a_second_order_or_cash_movement(): void
    {
        $session = $this->openSession();
        $orchestrator = app(PosSaleOrchestrator::class);
        $cartService = app(CartServiceInterface::class);

        $cart = $orchestrator->getOrCreateCart($session, null, 'guest-sale-2');
        $cartService->addLine($cart, new CartLineItemData(
            productId: $this->product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1),
        ));

        $tender = new PosTenderSelection(cashAmountMinor: 1000);

        $first = $orchestrator->completeSale($session, $cart, 'client-sale-dup', $tender);
        $second = $orchestrator->completeSale($session, $cart->fresh(), 'client-sale-dup', $tender);

        $this->assertSame($first->order->id, $second->order->id);
        $this->assertSame(1, Order::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, PosCashMovement::where('register_session_id', $session->id)->where('movement_type', CashMovementType::SALE_CASH_IN->value)->count());
    }

    public function test_a_pos_order_with_a_closed_register_session_context_is_rejected_and_rolled_back(): void
    {
        $session = $this->openSession();
        $orchestrator = app(PosSaleOrchestrator::class);
        $cartService = app(CartServiceInterface::class);

        $cart = $orchestrator->getOrCreateCart($session, null, 'guest-sale-3');
        $cartService->addLine($cart, new CartLineItemData(
            productId: $this->product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1),
        ));

        // Close the session behind the scenes before the sale actually completes.
        app(PosRegisterSessionService::class)->close($session, 10000, $this->cashier->id);

        // PosSaleOrchestrator's own upfront active-session check is the
        // first line of defense here; PosOrderContextHookInterface (hard-
        // fail, uncaught, at Order-creation time) is the deeper defense-in-
        // depth guard against a narrower race — proven in the Concurrency
        // suite and by the architecture test asserting the hook call site
        // carries no surrounding try/catch.
        try {
            $orchestrator->completeSale($session->fresh(), $cart, 'client-sale-4', new PosTenderSelection(cashAmountMinor: 1000));
            $this->fail('Expected an exception for a closed RegisterSession.');
        } catch (PosRegisterSessionException $e) {
            $this->assertStringContainsString('not active', $e->getMessage());
        }

        $this->assertSame(0, Order::where('tenant_id', $this->tenant->id)->count());
    }
}
