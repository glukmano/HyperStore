<?php

declare(strict_types=1);

namespace Tests\Feature\Affiliate;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Affiliate\Contracts\AffiliateAttributionServiceInterface;
use Modules\Affiliate\Enums\AffiliateAttributionStrategy;
use Modules\Affiliate\Enums\AffiliateConversionStatus;
use Modules\Affiliate\Enums\AffiliateStatus;
use Modules\Affiliate\Models\Affiliate;
use Modules\Affiliate\Models\AffiliateClick;
use Modules\Affiliate\Models\AffiliateCommissionRule;
use Modules\Affiliate\Models\AffiliateConversion;
use Modules\Affiliate\Models\AffiliatePayableEntry;
use Modules\Affiliate\Models\AffiliateReferralCode;
use Modules\Cart\Models\Cart;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Order\Events\OrderStatusChanged;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Payment\Events\PaymentPartiallyRefunded;
use Modules\Payment\Events\PaymentRefunded;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentTransaction;
use Tests\TestCase;

class AffiliateAttributionAndCommissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Affiliate $affiliate;

    private AffiliateAttributionServiceInterface $attributionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Attribution Tenant', 'slug' => 'attr-tenant']);

        $this->affiliate = Affiliate::create([
            'tenant_id' => $this->tenant->id,
            'display_name' => 'Test Affiliate',
            'status' => AffiliateStatus::Active,
            'payout_currency' => 'EUR',
            'applied_at' => now(),
        ]);

        AffiliateCommissionRule::create([
            'tenant_id' => $this->tenant->id,
            'affiliate_id' => $this->affiliate->id,
            'rate_basis_points' => 1000, // 10%
            'fixed_fee_minor' => 50,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        $this->attributionService = app(AffiliateAttributionServiceInterface::class);
    }

    private function makeOrder(int $subtotalMinor, int $discountMinor, string $currency = 'EUR'): Order
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'S', 'slug' => 'store-'.Str::random(6), 'status' => 'active', 'url' => 'https://s.example.com']);
        $market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'M'.Str::random(4), 'name' => 'Market', 'default_currency_code' => $currency, 'default_locale_code' => 'en', 'timezone' => 'UTC', 'is_active' => true]);
        $channel = Channel::create(['name' => 'Web', 'type' => 'website', 'handle' => 'web-'.Str::random(6), 'is_active' => true]);

        $cart = Cart::create([
            'tenant_id' => $this->tenant->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'currency' => $currency,
            'locale' => 'en',
            'status' => 'active',
        ]);

        $session = CheckoutSession::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'cart_id' => $cart->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'currency' => $currency,
            'locale' => 'en',
            'state' => 'ready_for_order',
        ]);

        $order = Order::create([
            'order_number' => 'ORD-'.Str::random(8),
            'tenant_id' => $this->tenant->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'checkout_id' => $session->id,
            'currency' => $currency,
            'locale' => 'en',
            'order_status' => 'placed',
            'payment_status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'merchandise_subtotal_minor' => $subtotalMinor,
            'discount_total_minor' => $discountMinor,
            'tax_total_minor' => 0,
            'shipping_total_minor' => 0,
            'grand_total_minor' => $subtotalMinor - $discountMinor,
            'commercial_model_snapshot' => 'platform_as_merchant_of_record',
            'customer_snapshot' => ['email' => 'buyer@example.com'],
            'version' => 1,
            'placed_at' => now(),
        ]);

        OrderItem::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'sku_snapshot' => 'SKU-1',
            'name_snapshot' => 'Product',
            'product_type_snapshot' => 'physical',
            'requires_shipping_snapshot' => false,
            'quantity' => '1.00000000',
            'unit_price_minor' => $subtotalMinor,
            'subtotal_minor' => $subtotalMinor,
            'discount_minor' => $discountMinor,
            'tax_minor' => 0,
            'total_minor' => $subtotalMinor - $discountMinor,
        ]);

        return $order->fresh(['items']);
    }

    private function clickCode(string $tokenHash): AffiliateReferralCode
    {
        $code = AffiliateReferralCode::create([
            'tenant_id' => $this->tenant->id,
            'affiliate_id' => $this->affiliate->id,
            'code' => 'CODE'.Str::random(6),
            'target_type' => 'platform',
            'is_active' => true,
        ]);

        AffiliateClick::create([
            'tenant_id' => $this->tenant->id,
            'affiliate_referral_code_id' => $code->id,
            'visitor_token_hash' => $tokenHash,
            'clicked_at' => now(),
        ]);

        return $code;
    }

    public function test_attribution_and_commission_are_frozen_at_order_creation(): void
    {
        $tokenHash = hash('sha256', 'visitor-1');
        $this->clickCode($tokenHash);

        $order = $this->makeOrder(10000, 1000); // base = 9000

        $attribution = $this->attributionService->freezeAttributionForOrder($order, $tokenHash, null);

        $this->assertNotNull($attribution);
        $this->assertSame($this->affiliate->id, $attribution->affiliate_id);
        $this->assertSame(AffiliateAttributionStrategy::LastClick, $attribution->attribution_strategy);

        $conversion = AffiliateConversion::where('affiliate_attribution_id', $attribution->id)->firstOrFail();
        $this->assertSame(AffiliateConversionStatus::Pending, $conversion->status);

        $item = $conversion->items()->firstOrFail();
        $this->assertSame(9000, $item->commissionable_base_minor);
        // 10% of 9000 = 900 + 50 fixed fee = 950
        $this->assertSame(950, $item->commission_amount_minor);
    }

    public function test_payment_paid_activates_frozen_commission_into_payable_entries(): void
    {
        $tokenHash = hash('sha256', 'visitor-2');
        $this->clickCode($tokenHash);
        $order = $this->makeOrder(10000, 0);

        $this->attributionService->freezeAttributionForOrder($order, $tokenHash, null);

        OrderStatusChanged::dispatch($order, 'payment', 'pending', 'paid');

        $conversion = AffiliateConversion::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(AffiliateConversionStatus::Accrued, $conversion->fresh()->status);

        $this->assertDatabaseHas('affiliate_payable_entries', [
            'tenant_id' => $this->tenant->id,
            'affiliate_id' => $this->affiliate->id,
            'entry_type' => 'earning',
        ]);
    }

    public function test_no_commission_rule_in_order_currency_means_no_commission_ever_silently_converted(): void
    {
        $tokenHash = hash('sha256', 'visitor-3');
        $this->clickCode($tokenHash);
        // Order in USD, but the only commission rule is EUR-only.
        $order = $this->makeOrder(10000, 0, 'USD');

        $attribution = $this->attributionService->freezeAttributionForOrder($order, $tokenHash, null);
        $conversion = AffiliateConversion::where('affiliate_attribution_id', $attribution->id)->firstOrFail();

        $this->assertSame(0, $conversion->items()->count());
    }

    public function test_full_refund_reverses_entire_commission(): void
    {
        $tokenHash = hash('sha256', 'visitor-4');
        $this->clickCode($tokenHash);
        $order = $this->makeOrder(10000, 0);
        $this->attributionService->freezeAttributionForOrder($order, $tokenHash, null);
        OrderStatusChanged::dispatch($order, 'payment', 'pending', 'paid');

        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->id,
            'status' => 'refunded',
            'amount_minor' => 10000,
            'currency' => 'EUR',
            'authorized_amount_minor' => 10000,
            'captured_amount_minor' => 10000,
            'refunded_amount_minor' => 10000,
        ]);
        $transaction = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'uuid' => (string) Str::uuid(),
            'payment_id' => $payment->id,
            'operation_type' => 'refund',
            'status' => 'succeeded',
            'amount_minor' => 10000,
            'currency' => 'EUR',
        ]);

        PaymentRefunded::dispatch($payment, $transaction);

        $conversion = AffiliateConversion::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(AffiliateConversionStatus::Reversed, $conversion->fresh()->status);

        $this->assertDatabaseHas('affiliate_payable_entries', [
            'tenant_id' => $this->tenant->id,
            'entry_type' => 'refund_adjustment',
        ]);

        // Replaying the same refund transaction must not double-reverse.
        PaymentRefunded::dispatch($payment, $transaction);
        $this->assertSame(1, AffiliatePayableEntry::where('entry_type', 'refund_adjustment')->count());
    }

    public function test_partial_refund_reverses_commission_proportionally(): void
    {
        $tokenHash = hash('sha256', 'visitor-5');
        $this->clickCode($tokenHash);
        $order = $this->makeOrder(10000, 0);
        $this->attributionService->freezeAttributionForOrder($order, $tokenHash, null);
        OrderStatusChanged::dispatch($order, 'payment', 'pending', 'paid');

        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->id,
            'status' => 'partially_refunded',
            'amount_minor' => 10000,
            'currency' => 'EUR',
            'authorized_amount_minor' => 10000,
            'captured_amount_minor' => 10000,
            'refunded_amount_minor' => 5000,
        ]);
        $transaction = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'uuid' => (string) Str::uuid(),
            'payment_id' => $payment->id,
            'operation_type' => 'refund',
            'status' => 'succeeded',
            'amount_minor' => 5000,
            'currency' => 'EUR',
        ]);

        PaymentPartiallyRefunded::dispatch($payment, $transaction);

        $conversion = AffiliateConversion::where('order_id', $order->id)->firstOrFail();
        // 50% refunded -> conversion stays accrued (not fully reversed).
        $this->assertSame(AffiliateConversionStatus::Accrued, $conversion->fresh()->status);

        // Original commission was 1000 bps of 10000 (=1000) + 50 fixed fee = 1050.
        // A 50% refund ratio reverses 50% of that frozen commission: 525.
        $refundEntry = AffiliatePayableEntry::where('entry_type', 'refund_adjustment')->firstOrFail();
        $this->assertSame(525, $refundEntry->commission_amount_minor);
    }

    public function test_manual_reattribution_after_accrual_reverses_previous_and_creates_new(): void
    {
        $tokenHash = hash('sha256', 'visitor-6');
        $this->clickCode($tokenHash);
        $order = $this->makeOrder(10000, 0);
        $this->attributionService->freezeAttributionForOrder($order, $tokenHash, null);
        OrderStatusChanged::dispatch($order, 'payment', 'pending', 'paid');

        $newAffiliate = Affiliate::create([
            'tenant_id' => $this->tenant->id,
            'display_name' => 'New Affiliate',
            'status' => AffiliateStatus::Active,
            'payout_currency' => 'EUR',
            'applied_at' => now(),
        ]);
        AffiliateCommissionRule::create([
            'tenant_id' => $this->tenant->id,
            'affiliate_id' => $newAffiliate->id,
            'rate_basis_points' => 500,
            'fixed_fee_minor' => 0,
            'currency' => 'EUR',
            'is_active' => true,
        ]);

        $admin = User::factory()->create();
        $newAttribution = $this->attributionService->manuallyReattribute($order, $newAffiliate->id, actingUserId: $admin->id);

        $this->assertSame($newAffiliate->id, $newAttribution->affiliate_id);
        $this->assertTrue($newAttribution->is_manual);

        // Old affiliate's earning must be reversed via a compensating entry.
        $this->assertDatabaseHas('affiliate_payable_entries', [
            'tenant_id' => $this->tenant->id,
            'affiliate_id' => $this->affiliate->id,
            'entry_type' => 'refund_adjustment',
        ]);

        // New affiliate has its own pending conversion (not yet activated).
        $newConversion = AffiliateConversion::where('affiliate_attribution_id', $newAttribution->id)->firstOrFail();
        $this->assertSame(AffiliateConversionStatus::Pending, $newConversion->status);
    }
}
