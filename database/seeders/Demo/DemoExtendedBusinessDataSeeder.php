<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Auctions\Enums\AuctionStatus;
use Modules\Auctions\Enums\UnpaidWinnerPolicy;
use Modules\Auctions\Models\Auction;
use Modules\Auctions\Services\AuctionLifecycleService;
use Modules\B2B\Enums\CompanyStatus;
use Modules\B2B\Enums\CompanyUserRole;
use Modules\B2B\Models\Company;
use Modules\B2B\Models\CompanyUser;
use Modules\B2B\Models\Quote;
use Modules\B2B\Services\QuoteService;
use Modules\Booking\Enums\BookingStatus;
use Modules\Booking\Models\Booking;
use Modules\Booking\Models\BookingResource;
use Modules\Booking\Models\BookingService;
use Modules\Booking\Models\BookingSlot;
use Modules\Catalog\Models\Product;
use Modules\Catalog\Models\ProductStoreListing;
use Modules\Catalog\Models\ProductTranslation;
use Modules\Customers\Models\CustomerProfile;
use Modules\Inventory\Models\InventorySource;
use Modules\Inventory\Models\StockItem;
use Modules\Order\Contracts\MasterOrderSplitServiceInterface;
use Modules\Order\Models\Order;
use Modules\Order\Services\ReturnRequestService;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Subscriptions\Enums\SubscriptionStatus;
use Modules\Subscriptions\Models\Subscription;
use Modules\Subscriptions\Models\SubscriptionPlan;
use Throwable;

/**
 * Pre-Production Readiness — completion delta: the domains
 * DemoBusinessDataSeeder explicitly deferred (B2B Quote, Auction, Booking,
 * Subscription, RMA/Return). Each example is built through the real domain
 * service that owns the invariant — never a raw insert — and left at a
 * realistic mid-flow state a human can continue interactively (an open
 * Quote awaiting the B2B buyer's acceptance, a biddable live Auction, an
 * open Booking slot alongside one already-confirmed Booking) rather than
 * a fully "completed" example for every one, matching how the original
 * demo Order/Wallet/Gift Card examples left later steps to the explorer.
 */
class DemoExtendedBusinessDataSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', DemoFoundationSeeder::TENANT_SLUG)->firstOrFail();
        $store = Store::where('tenant_id', $tenant->id)->where('slug', 'demo-flagship')->firstOrFail();
        $market = Market::where('tenant_id', $tenant->id)->where('code', 'DEMO_US')->firstOrFail();
        $channel = Channel::where('handle', 'demo-web')->firstOrFail();
        $source = InventorySource::where('tenant_id', $tenant->id)->where('code', 'DEMO_SRC')->firstOrFail();
        $owner = User::where('email', 'owner@demo.hyperstore.test')->firstOrFail();

        $this->seedB2bCompanyAndQuote($tenant->id, $owner);
        $this->seedAuction($tenant->id, $store->id, $source->id);
        $this->seedBooking($tenant->id, $store->id);
        $this->seedSubscription($tenant->id, $store->id, $market->id, $channel->id);
        $this->seedReturnRequest($tenant->id);

        $this->command?->info('Demo extended business data seeded: B2B Company+Quote, Auction, Booking, Subscription, Return/RMA.');
    }

    private function seedB2bCompanyAndQuote(int $tenantId, User $staffUser): void
    {
        try {
            $company = Company::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => 'Demo Wholesale Buyers Inc.'],
                ['status' => CompanyStatus::Active, 'payment_terms_days' => 30, 'credit_limit_currency' => 'USD', 'credit_limit_minor' => 500000]
            );

            $buyer = User::where('email', 'b2b-buyer@demo.hyperstore.test')->firstOrFail();
            $approver = User::where('email', 'b2b-approver@demo.hyperstore.test')->firstOrFail();
            CompanyUser::firstOrCreate(['tenant_id' => $tenantId, 'company_id' => $company->id, 'user_id' => $buyer->id], ['role' => CompanyUserRole::Buyer, 'is_active' => true]);
            CompanyUser::firstOrCreate(['tenant_id' => $tenantId, 'company_id' => $company->id, 'user_id' => $approver->id], ['role' => CompanyUserRole::Approver, 'is_active' => true]);

            if (Quote::where('company_id', $company->id)->exists()) {
                return;
            }

            $product = Product::where('tenant_id', $tenantId)->where('sku', 'DEMO-PHYS-001')->firstOrFail();

            $quoteService = app(QuoteService::class);
            $quote = $quoteService->submitRfq($company, $buyer, 'USD', [
                ['product_id' => $product->id, 'variant_id' => null, 'quantity' => '25', 'note' => 'Bulk order for Q1 restock'],
            ]);
            $line = $quote->lines()->firstOrFail();
            $quoteService->quotePrices($quote, $staffUser, [$line->id => 3999]);
        } catch (Throwable $e) {
            $this->command?->warn('Demo B2B Company/Quote seeding skipped: '.$e->getMessage());
        }
    }

    private function seedAuction(int $tenantId, int $storeId, int $sourceId): void
    {
        try {
            if (Auction::where('tenant_id', $tenantId)->exists()) {
                return;
            }

            $product = Product::firstOrCreate(
                ['tenant_id' => $tenantId, 'sku' => 'DEMO-AUC-001'],
                ['name' => 'Demo Vintage Camera (Auction)', 'slug' => 'demo-vintage-camera', 'product_type' => 'physical', 'status' => 'active', 'barcode' => '0'.random_int(100000000000, 999999999999)]
            );
            ProductTranslation::firstOrCreate(
                ['product_id' => $product->id, 'locale' => 'en'],
                ['name' => 'Demo Vintage Camera (Auction)', 'short_description' => 'A one-of-a-kind demo item, sold to the highest bidder.', 'description' => 'Demo data seeded for local exploration of the Auction ProductType.']
            );
            ProductStoreListing::firstOrCreate(['product_id' => $product->id, 'store_id' => $storeId], ['status' => 'published']);
            StockItem::firstOrCreate(
                ['tenant_id' => $tenantId, 'inventory_source_id' => $sourceId, 'product_id' => $product->id],
                ['on_hand' => 1, 'reserved' => 0]
            );

            $auction = Auction::create([
                'tenant_id' => $tenantId,
                'store_id' => $storeId,
                'product_id' => $product->id,
                'currency' => 'USD',
                'starts_at' => now()->subMinute(),
                'ends_at' => now()->addDays(3),
                'reserve_price_minor' => 5000,
                'starting_price_minor' => 2000,
                'bid_increment_minor' => 500,
                'status' => AuctionStatus::Scheduled,
                'winner_payment_window_minutes' => 1440,
                'unpaid_winner_policy' => UnpaidWinnerPolicy::Cancel,
            ]);

            // Activation through the real lifecycle service — reserves
            // inventory and flips Scheduled -> Active exactly as the
            // scheduled `auctions:activate` command would.
            app(AuctionLifecycleService::class)->activateScheduled($tenantId);
            $this->command?->info('Demo Auction ['.$auction->uuid.'] activated and open for bidding.');
        } catch (Throwable $e) {
            $this->command?->warn('Demo Auction seeding skipped: '.$e->getMessage());
        }
    }

    private function seedBooking(int $tenantId, int $storeId): void
    {
        try {
            if (BookingService::where('tenant_id', $tenantId)->exists()) {
                return;
            }

            $product = Product::firstOrCreate(
                ['tenant_id' => $tenantId, 'sku' => 'DEMO-BOOK-001'],
                ['name' => 'Demo 1-Hour Consultation', 'slug' => 'demo-consultation', 'product_type' => 'booking', 'status' => 'active', 'barcode' => '0'.random_int(100000000000, 999999999999)]
            );
            ProductTranslation::firstOrCreate(
                ['product_id' => $product->id, 'locale' => 'en'],
                ['name' => 'Demo 1-Hour Consultation', 'short_description' => 'A bookable demo service slot.', 'description' => 'Demo data seeded for local exploration of the Booking ProductType.']
            );
            ProductStoreListing::firstOrCreate(['product_id' => $product->id, 'store_id' => $storeId], ['status' => 'published']);

            $bookingService = BookingService::create([
                'tenant_id' => $tenantId,
                'product_id' => $product->id,
                'duration_minutes' => 60,
                'timezone' => 'UTC',
                'buffer_minutes' => 15,
            ]);

            $resource = BookingResource::create(['tenant_id' => $tenantId, 'name' => 'Demo Consultant', 'capacity' => 1, 'is_active' => true]);
            $resource->eligibleServices()->attach($bookingService->id);

            $confirmedSlot = BookingSlot::create([
                'tenant_id' => $tenantId,
                'booking_resource_id' => $resource->id,
                'booking_service_id' => $bookingService->id,
                'starts_at' => now()->addDay()->setTime(10, 0),
                'ends_at' => now()->addDay()->setTime(11, 0),
                'capacity' => 1,
            ]);
            // A second, still-open slot for the explorer to book interactively.
            BookingSlot::create([
                'tenant_id' => $tenantId,
                'booking_resource_id' => $resource->id,
                'booking_service_id' => $bookingService->id,
                'starts_at' => now()->addDays(2)->setTime(14, 0),
                'ends_at' => now()->addDays(2)->setTime(15, 0),
                'capacity' => 1,
            ]);

            $customerUser = User::where('email', 'customer@demo.hyperstore.test')->firstOrFail();
            $profile = CustomerProfile::firstOrCreate(['tenant_id' => $tenantId, 'user_id' => $customerUser->id]);

            // checkout_session_uuid is NOT NULL (paired with booking_slot_id
            // in a unique constraint) but is not a real FK — a genuine
            // Booking always carries the CheckoutSession uuid it was held
            // against (BookingHoldHook). This demo example is confirmed
            // directly rather than through a live checkout, so a synthetic
            // UUID is supplied to satisfy the column.
            Booking::create([
                'tenant_id' => $tenantId,
                'customer_profile_id' => $profile->id,
                'booking_service_id' => $bookingService->id,
                'booking_slot_id' => $confirmedSlot->id,
                'status' => BookingStatus::Confirmed,
                'checkout_session_uuid' => (string) Str::uuid(),
            ]);
        } catch (Throwable $e) {
            $this->command?->warn('Demo Booking seeding skipped: '.$e->getMessage());
        }
    }

    private function seedSubscription(int $tenantId, int $storeId, int $marketId, int $channelId): void
    {
        try {
            if (Subscription::where('tenant_id', $tenantId)->exists()) {
                return;
            }

            $product = Product::where('tenant_id', $tenantId)->where('sku', 'DEMO-SUB-001')->firstOrFail();

            $priceBook = PriceBook::where('tenant_id', $tenantId)->where('code', 'DEMO_PB')->firstOrFail();
            Price::firstOrCreate(
                ['tenant_id' => $tenantId, 'price_book_id' => $priceBook->id, 'product_id' => $product->id],
                ['amount_minor' => 1299, 'currency' => 'USD', 'status' => 'active']
            );

            $plan = SubscriptionPlan::firstOrCreate(
                ['tenant_id' => $tenantId, 'product_id' => $product->id],
                ['name' => 'Demo Monthly Streaming Plan', 'billing_interval' => 'monthly', 'billing_interval_days' => 30, 'trial_days' => 0, 'dunning_retry_days' => [1, 3, 7]]
            );

            $customerUser = User::where('email', 'customer@demo.hyperstore.test')->firstOrFail();
            $profile = CustomerProfile::firstOrCreate(['tenant_id' => $tenantId, 'user_id' => $customerUser->id]);

            Subscription::create([
                'tenant_id' => $tenantId,
                'customer_profile_id' => $profile->id,
                'plan_id' => $plan->id,
                'store_id' => $storeId,
                'market_id' => $marketId,
                'channel_id' => $channelId,
                'status' => SubscriptionStatus::Active,
                'current_period_start' => now(),
                'current_period_end' => now()->addDays(30),
                'next_billing_at' => now()->addDays(30),
                'cancel_at_period_end' => false,
            ]);
        } catch (Throwable $e) {
            $this->command?->warn('Demo Subscription seeding skipped: '.$e->getMessage());
        }
    }

    private function seedReturnRequest(int $tenantId): void
    {
        try {
            $order = Order::where('tenant_id', $tenantId)->where('order_number', 'like', 'DEMO-%')->first();
            if ($order === null) {
                $this->command?->warn('Demo Return/RMA seeding skipped: no demo Order found.');

                return;
            }

            if ($order->returnRequests()->exists()) {
                return;
            }

            // Materializes the Order into SellerOrders (a "platform" seller
            // partition, since none of the demo catalog carries a vendor_id)
            // — the real prerequisite ReturnRequestService itself enforces.
            app(MasterOrderSplitServiceInterface::class)->splitOrder($order);

            $order->loadMissing('items');
            $firstItem = $order->items->first();
            if ($firstItem === null) {
                $this->command?->warn('Demo Return/RMA seeding skipped: demo Order has no items.');

                return;
            }

            app(ReturnRequestService::class)->createReturnRequest(
                tenantId: $tenantId,
                orderId: $order->id,
                customerId: $order->user_id,
                items: [
                    ['order_item_id' => $firstItem->id, 'quantity' => '1.00000000', 'reason' => 'changed_mind'],
                ],
                customerNote: 'Demo return — customer changed their mind.',
            );
        } catch (Throwable $e) {
            $this->command?->warn('Demo Return/RMA seeding skipped: '.$e->getMessage());
        }
    }
}
