<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Core\Channels\Models\Channel;
use App\Core\Markets\Models\Market;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Cart\Models\Cart;
use Modules\Checkout\Models\CheckoutSession;
use Modules\Customers\Models\CustomerProfile;
use Modules\Customers\Models\CustomerReferral;
use Modules\Customers\Services\CustomerReferralService;
use Modules\Order\Events\OrderStatusChanged;
use Modules\Order\Models\Order;
use Modules\Promotions\Models\LoyaltyPointEntry;
use Modules\Promotions\Models\LoyaltyProgram;
use Modules\Promotions\Models\LoyaltyProgramCurrencyRule;
use Modules\Promotions\Services\LoyaltyService;
use Tests\TestCase;

class CustomerReferralServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private CustomerProfile $referrerProfile;

    private CustomerReferralService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Referral Tenant', 'slug' => 'referral-tenant']);

        $referrerUser = User::factory()->create();
        $this->referrerProfile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $referrerUser->id]);

        LoyaltyProgramCurrencyRule::create([
            'tenant_id' => $this->tenant->id,
            'loyalty_program_id' => LoyaltyProgram::create(['tenant_id' => $this->tenant->id, 'name' => 'P', 'is_active' => true])->id,
            'currency' => 'EUR',
            'minor_units_per_point' => 100,
            'point_redemption_value_minor' => 5,
            'is_active' => true,
        ]);

        $this->service = app(CustomerReferralService::class);
    }

    private function makeReferredOrder(CustomerProfile $referredProfile): Order
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'name' => 'S', 'slug' => 'store-'.Str::random(6), 'status' => 'active', 'url' => 'https://s.example.com']);
        $market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'M'.Str::random(4), 'name' => 'M', 'default_currency_code' => 'EUR', 'default_locale_code' => 'en', 'timezone' => 'UTC', 'is_active' => true]);
        $channel = Channel::create(['name' => 'Web', 'type' => 'website', 'handle' => 'web-'.Str::random(6), 'is_active' => true]);
        $cart = Cart::create(['tenant_id' => $this->tenant->id, 'store_id' => $store->id, 'market_id' => $market->id, 'channel_id' => $channel->id, 'currency' => 'EUR', 'locale' => 'en', 'status' => 'active']);
        $session = CheckoutSession::create(['uuid' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'cart_id' => $cart->id, 'store_id' => $store->id, 'market_id' => $market->id, 'channel_id' => $channel->id, 'currency' => 'EUR', 'locale' => 'en', 'state' => 'ready_for_order']);

        return Order::create([
            'order_number' => 'ORD-'.Str::random(8),
            'tenant_id' => $this->tenant->id,
            'store_id' => $store->id,
            'market_id' => $market->id,
            'channel_id' => $channel->id,
            'checkout_id' => $session->id,
            'user_id' => $referredProfile->user_id,
            'currency' => 'EUR',
            'locale' => 'en',
            'order_status' => 'placed',
            'payment_status' => 'paid',
            'fulfillment_status' => 'unfulfilled',
            'merchandise_subtotal_minor' => 5000,
            'discount_total_minor' => 0,
            'tax_total_minor' => 0,
            'shipping_total_minor' => 0,
            'grand_total_minor' => 5000,
            'commercial_model_snapshot' => 'platform_as_merchant_of_record',
            'customer_snapshot' => ['email' => 'referred@example.com'],
            'version' => 1,
            'placed_at' => now(),
        ]);
    }

    public function test_self_referral_is_blocked(): void
    {
        $code = $this->service->getOrCreateCode($this->referrerProfile);

        $result = $this->service->recordReferralSignup($this->referrerProfile, $code->code);

        $this->assertNull($result);
        $this->assertSame(0, CustomerReferral::count());
    }

    public function test_a_customer_can_only_be_referred_once(): void
    {
        $code = $this->service->getOrCreateCode($this->referrerProfile);

        $referredUser = User::factory()->create();
        $referredProfile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $referredUser->id]);

        $first = $this->service->recordReferralSignup($referredProfile, $code->code);
        $this->assertNotNull($first);

        $second = $this->service->recordReferralSignup($referredProfile, $code->code);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CustomerReferral::where('referred_customer_profile_id', $referredProfile->id)->count());
    }

    public function test_first_qualifying_paid_order_rewards_the_referrer_exactly_once(): void
    {
        $code = $this->service->getOrCreateCode($this->referrerProfile);
        $referredUser = User::factory()->create();
        $referredProfile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $referredUser->id]);
        $this->service->recordReferralSignup($referredProfile, $code->code);

        $order = $this->makeReferredOrder($referredProfile);
        OrderStatusChanged::dispatch($order, 'payment', 'pending', 'paid');

        $referral = CustomerReferral::where('referred_customer_profile_id', $referredProfile->id)->firstOrFail();
        $this->assertSame('rewarded', $referral->status);
        $this->assertNotNull($referral->rewarded_at);

        $loyaltyService = app(LoyaltyService::class);
        $this->assertSame(500, $loyaltyService->getAvailableBalance($this->referrerProfile));

        // A second paid Order for the same referred Customer must not reward again.
        $secondOrder = $this->makeReferredOrder($referredProfile);
        OrderStatusChanged::dispatch($secondOrder, 'payment', 'pending', 'paid');
        $this->assertSame(500, $loyaltyService->getAvailableBalance($this->referrerProfile));
    }

    /**
     * Final Completion Delta §5: the reward amount must be an explicit,
     * configurable LoyaltyProgram setting — not a hardcoded constant.
     * Changing it must affect only FUTURE rewards; a reward already granted
     * (an immutable ledger entry) must never be retroactively altered.
     */
    public function test_referral_reward_amount_is_configurable_and_historical_rewards_are_unaffected(): void
    {
        $program = LoyaltyProgram::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->assertSame(500, $program->referral_reward_points, 'Default preserves prior hardcoded behavior.');

        $codeA = $this->service->getOrCreateCode($this->referrerProfile);
        $referredUserA = User::factory()->create();
        $referredProfileA = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $referredUserA->id]);
        $this->service->recordReferralSignup($referredProfileA, $codeA->code);
        $orderA = $this->makeReferredOrder($referredProfileA);
        OrderStatusChanged::dispatch($orderA, 'payment', 'pending', 'paid');

        $referralA = CustomerReferral::where('referred_customer_profile_id', $referredProfileA->id)->firstOrFail();
        $entryA = LoyaltyPointEntry::where('source_type', 'customer_referral')
            ->where('source_uuid', 'customer_referral:'.$referralA->id)
            ->firstOrFail();
        $this->assertSame(500, $entryA->points);

        // Configuration change — no code change, no new deploy.
        $program->update(['referral_reward_points' => 1000]);

        $referrerUserB = User::factory()->create();
        $referrerProfileB = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $referrerUserB->id]);
        $codeB = $this->service->getOrCreateCode($referrerProfileB);
        $referredUserB = User::factory()->create();
        $referredProfileB = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $referredUserB->id]);
        $this->service->recordReferralSignup($referredProfileB, $codeB->code);
        $orderB = $this->makeReferredOrder($referredProfileB);
        OrderStatusChanged::dispatch($orderB, 'payment', 'pending', 'paid');

        $referralB = CustomerReferral::where('referred_customer_profile_id', $referredProfileB->id)->firstOrFail();
        $entryB = LoyaltyPointEntry::where('source_type', 'customer_referral')
            ->where('source_uuid', 'customer_referral:'.$referralB->id)
            ->firstOrFail();
        $this->assertSame(1000, $entryB->points, 'The new reward amount applies to the new referral.');

        // The historical entry for referral A must remain exactly as granted.
        $entryA->refresh();
        $this->assertSame(500, $entryA->points, 'A historical reward is never retroactively altered by a later configuration change.');
    }
}
