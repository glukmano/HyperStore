<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Models\CheckoutSession;
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
use Tests\TestCase;

class CheckoutMarketplaceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private Channel $channel;

    private Vendor $vendor;

    private Product $product;

    private VendorListing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Checkout MP Tenant',
            'slug' => 'chk-mp-tenant',
            'settings' => [
                'marketplace' => [
                    'commercial_model' => 'platform_as_merchant_of_record',
                ],
            ],
        ]);

        $this->store = Store::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Default Store',
            'slug' => 'default-store',
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
            'name' => 'English', 'native_name' => 'English',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->market = Market::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Europe',
            'code' => 'EU',
            'default_currency_code' => 'EUR', 'default_locale_code' => 'en',
        ]);

        $this->channel = Channel::create([
            'name' => 'Web Channel',
            'handle' => 'web',
        ]);

        $plan = VendorPlan::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'MP Plan',
            'code' => 'mp-plan',
        ]);

        $this->vendor = Vendor::create([
            'tenant_id' => $this->tenant->id,
            'vendor_plan_id' => $plan->id,
            'name' => 'Original Vendor Name',
            'platform_slug' => 'original-vendor',
            'legal_name' => 'Original Vendor Corp',
            'email' => 'vendor@orig.com',
            'payout_currency' => 'EUR',
            'operational_status' => VendorOperationalStatus::Active,
        ]);

        VendorStoreParticipation::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'store_id' => $this->store->id,
            'is_enabled' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'product_type' => 'simple',
            'sku' => 'PROD-VENDOR-1',
            'status' => 'active',
        ]);

        $this->listing = VendorListing::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'product_id' => $this->product->id,
            'product_variant_id' => null,
            'vendor_sku' => 'V-SKU-100',
        ]);

        VendorListingStoreAvailability::create([
            'tenant_id' => $this->tenant->id,
            'vendor_listing_id' => $this->listing->id,
            'store_id' => $this->store->id,
            'is_enabled' => true,
        ]);

        // Commission: 10% (1000 bps) + 1.00 EUR fixed fee
        VendorCommissionRule::create([
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'category_id' => null,
            'rate_basis_points' => 1000,
            'fixed_fee_minor' => 100,
            'currency' => 'EUR',
            'is_active' => true,
        ]);
    }

    public function test_order_creation_persists_marketplace_snapshots_and_remains_historically_immutable(): void
    {
        $cart = Cart::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'EUR',
            'locale' => 'en',
            'status' => 'converted',
        ]);

        $checkout = CheckoutSession::create([
            'tenant_id' => $this->tenant->id,
            'cart_id' => $cart->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'currency' => 'EUR',
            'locale' => 'en',
            'status' => 'completed',
            'grand_total_minor' => 10000,
        ]);

        $order = Order::create([
            'uuid' => (string) Str::uuid(),
            'order_number' => 'ORD-MP-100',
            'tenant_id' => $this->tenant->id,
            'store_id' => $this->store->id,
            'market_id' => $this->market->id,
            'channel_id' => $this->channel->id,
            'checkout_id' => $checkout->id,
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
            'customer_snapshot' => ['email' => 'test@example.com'],
            'placed_at' => now(),
        ]);

        // Create OrderItem with Marketplace snapshots
        $orderItem = OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'sku_snapshot' => 'PROD-VENDOR-1',
            'name_snapshot' => 'Sample Product',
            'product_type_snapshot' => 'simple',
            'quantity' => '1.00000000',
            'unit_price_minor' => 10000,
            'subtotal_minor' => 10000,
            'line_discount_minor' => 0,
            'allocated_cart_discount_minor' => 0,
            'discount_minor' => 0,
            'taxable_amount_minor' => 10000,
            'tax_minor' => 0,
            'total_minor' => 10000,
            'vendor_uuid_snapshot' => $this->vendor->uuid,
            'vendor_name_snapshot' => $this->vendor->name,
            'vendor_listing_uuid_snapshot' => $this->listing->uuid,
            'commission_basis_minor' => 10000,
            'commission_rate_bps' => 1000,
            'commission_fixed_fee_minor' => 100,
            'commission_amount_minor' => 1100, // 10% of 10000 = 1000 + 100 = 1100
            'commission_currency' => 'EUR',
            'commission_rule_ref' => 'rule_abc',
            'vendor_id' => $this->vendor->id,
            'vendor_listing_id' => $this->listing->id,
        ]);

        $this->assertDatabaseHas('order_items', [
            'id' => $orderItem->id,
            'vendor_uuid_snapshot' => $this->vendor->uuid,
            'vendor_name_snapshot' => 'Original Vendor Name',
            'commission_amount_minor' => 1100,
        ]);

        // Vendor rename occurs in the future
        $this->vendor->name = 'Renamed Future Vendor';
        $this->vendor->save();

        // Historical order item snapshot remains unchanged
        $freshItem = $orderItem->fresh();
        $this->assertSame('Original Vendor Name', $freshItem->vendor_name_snapshot);

        // Fire Payment Paid event
        $order->payment_status = 'paid';
        $order->save();

        event(new OrderStatusChanged(
            order: $order,
            dimension: 'payment',
            fromStatus: 'unpaid',
            toStatus: 'paid'
        ));

        // Payable entry accrued: amount = 10000, commission = 1100, net = 8900
        $this->assertDatabaseHas('vendor_payable_entries', [
            'tenant_id' => $this->tenant->id,
            'vendor_id' => $this->vendor->id,
            'order_item_id' => $orderItem->id,
            'entry_type' => VendorPayableEntryType::Earning->value,
            'amount_minor' => 10000,
            'commission_amount_minor' => 1100,
            'net_amount_minor' => 8900,
        ]);
    }
}
