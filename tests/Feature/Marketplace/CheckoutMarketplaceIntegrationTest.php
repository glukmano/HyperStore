<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Cart\Models\CartLine;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Checkout\Services\CheckoutOrchestrator;
use Modules\Marketplace\Enums\VendorOperationalStatus;
use Modules\Marketplace\Enums\VendorPayableEntryType;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorCommissionRule;
use Modules\Marketplace\Models\VendorListing;
use Modules\Marketplace\Models\VendorListingStoreAvailability;
use Modules\Marketplace\Models\VendorPlan;
use Modules\Marketplace\Models\VendorStoreParticipation;
use Modules\Order\Events\OrderStatusChanged;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Tests\TestCase;

class CheckoutMarketplaceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private Channel $channel;

    private Vendor $vendorA;

    private Vendor $vendorB;

    private Product $product;

    private VendorListing $listingA;

    private VendorListing $listingB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Checkout MP Tenant',
            'slug' => 'chk-mp-tenant',
            'settings' => [
                'marketplace' => [
                    'commercial_model' => 'platform_as_merchant_of_record',
                    'payable_hold_days' => 14,
                ],
            ],
        ]);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Default Store',
            'slug' => 'default-store',
            'default_currency' => 'EUR',
            'is_active' => true,
        ]);

        DB::table('currencies')->insertOrIgnore([
            'code' => 'EUR',
            'name' => 'Euro',
            'symbol' => '€',
            'decimals' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('languages')->insertOrIgnore([
            'code' => 'en',
            'name' => 'English',
            'native_name' => 'English',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->market = Market::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Europe',
            'code' => 'EU',
            'default_currency_code' => 'EUR',
            'default_locale_code' => 'en',
        ]);

        $this->channel = Channel::create([
            'name' => 'Web Channel',
            'handle' => 'web',
        ]);

        $plan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Standard Plan',
            'code' => 'standard',
        ]);

        // Vendor A
        $this->vendorA = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'Vendor Alpha',
            'platform_slug' => 'vendor-alpha',
            'legal_name' => 'Vendor Alpha LLC',
            'email' => 'alpha@vendor.com',
            'payout_currency' => 'EUR',
            'operational_status' => VendorOperationalStatus::Active,
        ]);
        VendorStoreParticipation::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendorA->id,
            'store_id' => $this->store->id,
            'is_enabled' => true,
        ]);

        // Vendor B
        $this->vendorB = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'Vendor Beta',
            'platform_slug' => 'vendor-beta',
            'legal_name' => 'Vendor Beta LLC',
            'email' => 'beta@vendor.com',
            'payout_currency' => 'EUR',
            'operational_status' => VendorOperationalStatus::Active,
        ]);
        VendorStoreParticipation::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendorB->id,
            'store_id' => $this->store->id,
            'is_enabled' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'product_type' => 'digital',
            'sku' => 'PROD-MULTI-1',
            'status' => 'active',
        ]);

        TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'STD_TAX', 'name' => 'Standard Tax', 'is_default' => true]);

        $priceBook = PriceBook::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Base EUR Price Book',
            'code' => 'base-eur',
            'currency' => 'EUR',
            'priority' => 0,
            'is_default' => true,
            'status' => 'active',
        ]);

        Price::create([
            'tenant_id' => $this->tenant->id,
            'price_book_id' => $priceBook->id,
            'product_id' => $this->product->id,
            'product_variant_id' => null,
            'amount_minor' => 10000,
            'currency' => 'EUR',
            'status' => 'active',
        ]);

        // Competing Listing A
        $this->listingA = VendorListing::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendorA->id,
            'product_id' => $this->product->id,
            'product_variant_id' => null,
            'vendor_sku' => 'V-SKU-ALPHA',
        ]);
        VendorListingStoreAvailability::create([
            'tenant_id' => $this->tenant->id,
            'vendor_listing_id' => $this->listingA->id,
            'store_id' => $this->store->id,
            'is_enabled' => true,
        ]);

        // Competing Listing B
        $this->listingB = VendorListing::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendorB->id,
            'product_id' => $this->product->id,
            'product_variant_id' => null,
            'vendor_sku' => 'V-SKU-BETA',
        ]);
        VendorListingStoreAvailability::create([
            'tenant_id' => $this->tenant->id,
            'vendor_listing_id' => $this->listingB->id,
            'store_id' => $this->store->id,
            'is_enabled' => true,
        ]);

        // Commission Rules: Vendor A = 10% (1000 bps) + 100 fixed, Vendor B = 20% (2000 bps) + 200 fixed
        VendorCommissionRule::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendorA->id,
            'category_id' => null,
            'rate_basis_points' => 1000,
            'fixed_fee_minor' => 100,
            'currency' => 'EUR',
            'is_active' => true,
        ]);
        VendorCommissionRule::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendorB->id,
            'category_id' => null,
            'rate_basis_points' => 2000,
            'fixed_fee_minor' => 200,
            'currency' => 'EUR',
            'is_active' => true,
        ]);
    }

    public function test_scenario_a_customer_chooses_vendor_b_freezes_vendor_b_and_accrues_vendor_b_only(): void
    {
        $cart = Cart::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'EUR',
            'locale' => 'en',
            'status' => 'active',
            'version' => 1,
        ]);

        // Line specifically carries Vendor B listing UUID
        CartLine::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'quantity' => '1.00000000',
            'unit_price_minor' => 10000,
            'total_minor' => 10000,
            'signature' => 'sig_b',
            'options' => ['vendor_listing_uuid' => $this->listingB->uuid],
        ]);

        // Create Checkout Session in customer_info_ready state
        $session = CheckoutSession::create([
            'tenant_id' => $this->tenant->id,
            'cart_id' => $cart->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'EUR',
            'locale' => 'en',
            'state' => 'customer_info_ready',
            'evaluated_cart_version' => 1,
            'customer_data' => ['email' => 'b_customer@test.com', 'first_name' => 'Jane', 'last_name' => 'Doe'],
            'expires_at' => CarbonImmutable::now()->addHours(2),
            'grand_total_minor' => 10000,
        ]);

        /** @var CheckoutOrchestrator $orchestrator */
        $orchestrator = app(CheckoutOrchestrator::class);
        $readyRes = $orchestrator->markReadyForOrder($session);

        $readySnapshot = $session->fresh()->ready_snapshot;
        $this->assertNotNull($readySnapshot);
        $this->assertNotEmpty($readySnapshot['lines']);
        $lineSnapshot = $readySnapshot['lines'][0];

        // MUST be Vendor B
        $this->assertSame($this->vendorB->uuid, $lineSnapshot['vendor_uuid_snapshot']);
        $this->assertSame('Vendor Beta', $lineSnapshot['vendor_name_snapshot']);
        $this->assertSame($this->listingB->uuid, $lineSnapshot['vendor_listing_uuid_snapshot']);
        // 20% of 10000 = 2000 + 200 = 2200
        $this->assertSame(2200, $lineSnapshot['commission_amount_minor']);

        // Create Order via OrderItem consuming frozen ready_snapshot
        $order = Order::create([
            'uuid' => (string) Str::uuid(),
            'order_number' => 'ORD-SCENARIO-A',
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'checkout_id' => $session->id,
            'currency' => 'EUR',
            'locale' => 'en',
            'order_status' => 'placed',
            'payment_status' => 'unpaid',
            'fulfillment_status' => 'unfulfilled',
            'merchandise_subtotal_minor' => 10000,
            'discount_total_minor' => 0,
            'shipping_total_minor' => 0,
            'tax_total_minor' => 0,
            'grand_total_minor' => 10000,
            'customer_snapshot' => ['email' => 'a@test.com'],
            'placed_at' => now(),
        ]);

        $orderItem = OrderItem::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'sku_snapshot' => 'PROD-MULTI-1',
            'name_snapshot' => 'Sample',
            'quantity' => '1.00000000',
            'unit_price_minor' => 10000,
            'subtotal_minor' => 10000,
            'total_minor' => 10000,
            'vendor_uuid_snapshot' => $lineSnapshot['vendor_uuid_snapshot'],
            'vendor_name_snapshot' => $lineSnapshot['vendor_name_snapshot'],
            'vendor_listing_uuid_snapshot' => $lineSnapshot['vendor_listing_uuid_snapshot'],
            'commission_basis_minor' => $lineSnapshot['commission_basis_minor'],
            'commission_rate_bps' => $lineSnapshot['commission_rate_bps'],
            'commission_fixed_fee_minor' => $lineSnapshot['commission_fixed_fee_minor'],
            'commission_amount_minor' => $lineSnapshot['commission_amount_minor'],
            'commission_currency' => $lineSnapshot['commission_currency'],
            'vendor_id' => $lineSnapshot['vendor_id'],
            'vendor_listing_id' => $lineSnapshot['vendor_listing_id'],
        ]);

        // Fire paid event
        $order->payment_status = 'paid';
        $order->save();
        event(new OrderStatusChanged($order, 'payment', 'unpaid', 'paid'));

        // Accrual check: Vendor B has earning, Vendor A has NOTHING
        $this->assertDatabaseHas('vendor_payable_entries', [
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendorB->id,
            'entry_type' => VendorPayableEntryType::Earning->value,
            'amount_minor' => 10000,
            'commission_amount_minor' => 2200,
            'net_amount_minor' => 7800,
        ]);

        $this->assertDatabaseMissing('vendor_payable_entries', [
            'vendor_id' => $this->vendorA->id,
        ]);
    }

    public function test_scenario_b_vendor_a_disabled_in_store_vendor_b_chosen_succeeds_without_first_lookup(): void
    {
        // Disable Vendor A listing in store
        VendorListingStoreAvailability::where('vendor_listing_id', $this->listingA->id)
            ->where('store_id', $this->store->id)
            ->update(['is_enabled' => false]);

        $cart = Cart::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'EUR',
            'locale' => 'en',
            'status' => 'active',
            'version' => 1,
        ]);

        // Customer chooses Vendor B
        CartLine::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'quantity' => '1.00000000',
            'unit_price_minor' => 10000,
            'total_minor' => 10000,
            'signature' => 'sig_b_avail',
            'options' => ['vendor_listing_uuid' => $this->listingB->uuid],
        ]);

        $session = CheckoutSession::create([
            'tenant_id' => $this->tenant->id,
            'cart_id' => $cart->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'EUR',
            'locale' => 'en',
            'state' => 'customer_info_ready',
            'evaluated_cart_version' => 1,
            'customer_data' => ['email' => 'b_avail@test.com', 'first_name' => 'Jane', 'last_name' => 'Doe'],
            'expires_at' => CarbonImmutable::now()->addHours(2),
            'grand_total_minor' => 10000,
        ]);

        /** @var CheckoutOrchestrator $orchestrator */
        $orchestrator = app(CheckoutOrchestrator::class);
        $orchestrator->markReadyForOrder($session);

        $lineSnapshot = $session->fresh()->ready_snapshot['lines'][0];
        $this->assertSame($this->vendorB->uuid, $lineSnapshot['vendor_uuid_snapshot']);
        $this->assertSame($this->listingB->uuid, $lineSnapshot['vendor_listing_uuid_snapshot']);
    }

    public function test_scenario_c_same_product_from_two_vendors_produces_separate_lines_and_distinct_commission_snapshots(): void
    {
        $cart = Cart::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'EUR',
            'locale' => 'en',
            'status' => 'active',
            'version' => 1,
        ]);

        // Line 1: Product P from Vendor A
        CartLine::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'quantity' => '1.00000000',
            'unit_price_minor' => 10000,
            'total_minor' => 10000,
            'signature' => 'sig_line_a',
            'options' => ['vendor_listing_uuid' => $this->listingA->uuid],
        ]);

        // Line 2: SAME Product P from Vendor B
        CartLine::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'quantity' => '1.00000000',
            'unit_price_minor' => 10000,
            'total_minor' => 10000,
            'signature' => 'sig_line_b',
            'options' => ['vendor_listing_uuid' => $this->listingB->uuid],
        ]);

        $session = CheckoutSession::create([
            'tenant_id' => $this->tenant->id,
            'cart_id' => $cart->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'EUR',
            'locale' => 'en',
            'state' => 'customer_info_ready',
            'evaluated_cart_version' => 1,
            'customer_data' => ['email' => 'multi_vendor@test.com', 'first_name' => 'Jane', 'last_name' => 'Doe'],
            'expires_at' => CarbonImmutable::now()->addHours(2),
            'grand_total_minor' => 20000,
        ]);

        /** @var CheckoutOrchestrator $orchestrator */
        $orchestrator = app(CheckoutOrchestrator::class);
        $orchestrator->markReadyForOrder($session);

        $lines = $session->fresh()->ready_snapshot['lines'];
        $this->assertCount(2, $lines);

        // First line: Vendor A
        $this->assertSame($this->vendorA->uuid, $lines[0]['vendor_uuid_snapshot']);
        $this->assertSame($this->listingA->uuid, $lines[0]['vendor_listing_uuid_snapshot']);
        $this->assertSame(1100, $lines[0]['commission_amount_minor']); // 10% + 100

        // Second line: Vendor B
        $this->assertSame($this->vendorB->uuid, $lines[1]['vendor_uuid_snapshot']);
        $this->assertSame($this->listingB->uuid, $lines[1]['vendor_listing_uuid_snapshot']);
        $this->assertSame(2200, $lines[1]['commission_amount_minor']); // 20% + 200
    }
}
