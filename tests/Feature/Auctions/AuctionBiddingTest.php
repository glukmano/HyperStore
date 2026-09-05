<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Currency;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auctions\Enums\AuctionStatus;
use Modules\Auctions\Exceptions\AuctionClosedException;
use Modules\Auctions\Exceptions\AuctionException;
use Modules\Auctions\Exceptions\AuctionNotEligibleForCartException;
use Modules\Auctions\Exceptions\BidTooLowException;
use Modules\Auctions\Models\Auction;
use Modules\Auctions\Models\Bid;
use Modules\Auctions\Services\AuctionBiddingService;
use Modules\Auctions\Services\AuctionLifecycleService;
use Modules\Auctions\Services\AuctionSettlementService;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Customers\Models\CustomerProfile;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Pricing\Models\TaxClass;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingMethodZone;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneRule;
use Tests\TestCase;

class AuctionBiddingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Product $product;

    private CustomerProfile $profileA;

    private CustomerProfile $profileB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Currency::firstOrCreate(['code' => 'CHF'], ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_active' => true]);

        $this->tenant = Tenant::create(['name' => 'Auction Tenant', 'slug' => 'auction-tenant']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'A_S1', 'name' => 'Store', 'slug' => 'a-s1', 'status' => 'active']);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'AUCTION-ITEM',
            'name' => 'Rare Item',
            'slug' => 'rare-item',
            'product_type' => 'auction',
            'status' => 'active',
        ]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $this->profileA = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $userA->id]);
        $this->profileB = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $userB->id]);
    }

    private function makeAuction(array $overrides = []): Auction
    {
        return Auction::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'product_id' => $this->product->id,
            'currency' => 'CHF',
            'starts_at' => CarbonImmutable::now()->subMinute(),
            'ends_at' => CarbonImmutable::now()->addHour(),
            'starting_price_minor' => 1000,
            'bid_increment_minor' => 100,
            'status' => AuctionStatus::Active,
            'winner_payment_window_minutes' => 1440,
        ], $overrides));
    }

    public function test_a_bid_below_the_floor_is_rejected(): void
    {
        $auction = $this->makeAuction();
        $service = app(AuctionBiddingService::class);

        $this->expectException(BidTooLowException::class);
        $service->placeBid($this->tenant->id, $auction->id, $this->profileA, 500);
    }

    public function test_bidding_after_close_is_rejected(): void
    {
        $auction = $this->makeAuction(['ends_at' => CarbonImmutable::now()->subMinute()]);
        $service = app(AuctionBiddingService::class);

        $this->expectException(AuctionClosedException::class);
        $service->placeBid($this->tenant->id, $auction->id, $this->profileA, 1000);
    }

    public function test_successive_bids_repoint_current_bid_id_and_never_mutate_historical_bid_rows(): void
    {
        $auction = $this->makeAuction();
        $service = app(AuctionBiddingService::class);

        $bid1 = $service->placeBid($this->tenant->id, $auction->id, $this->profileA, 1000);
        $bid2 = $service->placeBid($this->tenant->id, $auction->id, $this->profileB, 1100);

        $auction->refresh();
        $this->assertSame($bid2->id, $auction->current_bid_id);
        $this->assertSame(1100, $auction->current_price_minor);
        $this->assertSame($this->profileB->id, $auction->current_bidder_customer_profile_id);

        // Owner Delta §5: the FIRST bid row itself is completely untouched —
        // no status column, no mutation of any kind.
        $bid1->refresh();
        $this->assertSame(1000, $bid1->amount_minor);
        $this->assertFalse(in_array('status', array_keys($bid1->getAttributes()), true));

        $this->assertSame(2, Bid::where('auction_id', $auction->id)->count());
    }

    public function test_bid_rows_are_immutable_and_reject_update_attempts(): void
    {
        $auction = $this->makeAuction();
        $bid = app(AuctionBiddingService::class)->placeBid($this->tenant->id, $auction->id, $this->profileA, 1000);

        $this->expectException(AuctionException::class);
        $bid->amount_minor = 9999;
        $bid->save();
    }

    public function test_auction_product_cannot_be_added_to_an_ordinary_cart_while_active(): void
    {
        $this->makeAuction();

        $market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'CH', 'name' => 'Switzerland', 'default_currency_code' => 'CHF', 'default_locale_code' => 'en', 'is_active' => true]);
        $channel = Channel::create(['name' => 'Web', 'handle' => 'web-'.uniqid(), 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->store->id, 'channel_id' => $channel->id, 'is_active' => true]);

        $cartService = app(CartServiceInterface::class);
        $cart = $cartService->getOrCreateActiveCart(new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $market->id,
            channelId: $channel->id,
            currency: 'CHF',
            userId: $this->profileA->user_id,
        ));

        $this->expectException(AuctionNotEligibleForCartException::class);
        $cartService->addLine($cart, new CartLineItemData(
            productId: $this->product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1),
        ));
    }

    public function test_winner_checkout_freezes_the_winning_bid_snapshot_onto_the_order(): void
    {
        $market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'CH', 'name' => 'Switzerland', 'default_currency_code' => 'CHF', 'default_locale_code' => 'en', 'is_active' => true]);
        $this->store->markets()->attach($market->id, ['is_active' => true, 'is_default' => true]);
        $channel = Channel::create(['name' => 'Web', 'handle' => 'web-'.uniqid(), 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->store->id, 'channel_id' => $channel->id, 'is_active' => true]);
        TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'STD_TAX', 'name' => 'Standard Tax', 'is_default' => true]);

        $zone = ShippingZone::create(['tenant_id' => $this->tenant->id, 'code' => 'CH_ZONE', 'name' => 'CH Zone', 'status' => 'active']);
        ShippingZoneRule::create(['shipping_zone_id' => $zone->id, 'rule_type' => 'country', 'country_code' => 'CH']);
        $shippingMethod = ShippingMethod::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'STD_SHIP',
            'name' => 'Standard Shipping',
            'rate_calculator_type' => 'flat_rate',
            'currency' => 'CHF',
            'base_amount' => 0,
            'status' => 'active',
        ]);
        ShippingMethodZone::create(['shipping_method_id' => $shippingMethod->id, 'shipping_zone_id' => $zone->id]);

        // Owner Delta §7: the auctioned lot IS Inventory-backed here — this
        // exercises the real reserve-at-activation -> handoff-to-Order
        // pipeline, not merely a shortcut.
        $warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'WH-AUC', 'name' => 'WH Auction', 'country_code' => 'CH']);
        $source = InventorySource::create(['tenant_id' => $this->tenant->id, 'warehouse_id' => $warehouse->id, 'code' => 'SRC-AUC', 'name' => 'Source Auction', 'priority' => 10]);
        StockItem::create(['tenant_id' => $this->tenant->id, 'inventory_source_id' => $source->id, 'product_id' => $this->product->id, 'on_hand' => 1, 'reserved' => 0]);

        $auction = $this->makeAuction(['status' => AuctionStatus::Scheduled, 'starts_at' => CarbonImmutable::now()->subMinute()]);

        // Real activation: reserves Inventory + provisions the placeholder
        // price (used only by CheckoutShippingOrchestrator's independent,
        // non-authoritative price lookup — never the amount actually
        // charged, which is always the winning bid).
        app(AuctionLifecycleService::class)->activateScheduled($this->tenant->id);
        $auction->refresh();
        $this->assertSame(AuctionStatus::Active, $auction->status);
        $this->assertNotNull($auction->inventory_reservation_key);

        $biddingService = app(AuctionBiddingService::class);
        $biddingService->placeBid($this->tenant->id, $auction->id, $this->profileA, 1000);
        $winningBid = $biddingService->placeBid($this->tenant->id, $auction->id, $this->profileB, 1200);

        // Force-close regardless of real wall-clock timing in this test.
        $auction->refresh();
        $auction->update(['status' => AuctionStatus::Ended]);

        $settlementService = app(AuctionSettlementService::class);
        $session = $settlementService->createWinnerCheckout($auction->fresh());

        $this->assertSame($auction->id, $session->auction_id);

        $orchestrator = app(CheckoutOrchestratorInterface::class);
        $orchestrator->setCustomerData($session, new CheckoutCustomerData('winner@example.com', 'A', 'B'));
        $session = $orchestrator->setAddresses($session, new CheckoutAddress('A B', ['Street 1'], 'Zurich', 'CH', postalCode: '8001'));
        $session = $orchestrator->selectShippingQuote($session, ['method_id' => $shippingMethod->id, 'method_code' => $shippingMethod->code]);
        $session = $orchestrator->reserveInventory($session);
        $ready = $orchestrator->markReadyForOrder($session);

        $result = app(OrderCreationServiceInterface::class)->createFromCheckout(new OrderCreationDTO(
            tenantId: $ready->tenantId,
            checkoutId: $ready->checkoutSessionId,
        ));

        $order = $result->order;
        $order->load('items');
        $item = $order->items->first();

        $this->assertSame($auction->id, $item->auction_id);
        $this->assertSame($winningBid->id, $item->winning_bid_id);
        $this->assertSame(1200, $item->winning_bid_amount_minor);
        $this->assertSame('CHF', $item->auction_currency_snapshot);
        $this->assertTrue($item->reserve_met);
        $this->assertSame(AuctionStatus::Settled, $auction->fresh()->status);
    }
}
