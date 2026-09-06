<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use Modules\Catalog\Models\Product;
use Modules\Customers\Models\CustomerProfile;
use Modules\Order\Models\Order;
use Modules\Payment\Contracts\PaymentGatewayInterface;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\DTOs\GatewayCaptureRequest;
use Modules\Payment\DTOs\GatewayPaymentRequest;
use Modules\Payment\DTOs\GatewayPaymentResult;
use Modules\Payment\DTOs\GatewayRefundRequest;
use Modules\Payment\DTOs\GatewayVoidRequest;
use Modules\Payment\DTOs\SetupPaymentMethodRequest;
use Modules\Payment\Exceptions\GatewayDoesNotSupportRecurringException;
use Modules\Payment\Models\CustomerPaymentMethod;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Subscriptions\Enums\SubscriptionStatus;
use Modules\Subscriptions\Models\Subscription;
use Modules\Subscriptions\Models\SubscriptionPlan;
use Modules\Subscriptions\Models\SubscriptionRenewalAttempt;
use Modules\Subscriptions\Services\SubscriptionRenewalService;
use Modules\Subscriptions\Services\SubscriptionService;
use Tests\Feature\Ledger\LedgerTestCaseTrait;
use Tests\TestCase;

/**
 * Owner Delta §9/§11/D.42/D.44: Subscription renewal reuses the ordinary
 * Checkout/Order/Payment pipeline — no second Order engine. Renewal
 * serialization begins BEFORE any charge attempt; a duplicate cron run
 * never double-charges or double-creates an Order. A plan price/name
 * change never alters an already-frozen historical renewal snapshot.
 */
class SubscriptionLifecycleTest extends TestCase
{
    use LedgerTestCaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedgerTest();
        $this->provisionSystemAccounts();

        TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'STD_TAX', 'name' => 'Standard Tax', 'is_default' => true]);
    }

    private function makePlan(int $priceMinor = 1500): SubscriptionPlan
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'SUB-'.uniqid(),
            'name' => 'Streaming Plan',
            'slug' => 'sub-'.uniqid(),
            'product_type' => 'subscription',
            'status' => 'active',
        ]);

        $priceBook = PriceBook::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'SUB_PB_'.uniqid(),
            'name' => 'Subscription Price Book',
            'currency' => 'EUR',
            'status' => 'active',
            'priority' => 100,
        ]);

        Price::create([
            'tenant_id' => $this->tenant->id,
            'price_book_id' => $priceBook->id,
            'product_id' => $product->id,
            'amount_minor' => $priceMinor,
            'currency' => 'EUR',
            'status' => 'active',
        ]);

        return SubscriptionPlan::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $product->id,
            'name' => 'Streaming Monthly',
            'billing_interval' => 'monthly',
        ]);
    }

    private function makePaymentMethod(): CustomerPaymentMethod
    {
        /** @var PaymentGatewayInterface $gateway */
        $gateway = app(PaymentGatewayRegistryInterface::class)->default();
        $setup = $gateway->setupPaymentMethod(new SetupPaymentMethodRequest(
            tenantId: $this->tenant->id,
            customerProfileId: 1,
            paymentMethodType: 'card',
            paymentMethodReference: 'pm_test_1'
        ));

        return CustomerPaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'customer_profile_id' => 1,
            'gateway_provider_code' => $gateway->getProviderCode(),
            'gateway_reference' => (string) $setup->providerReference,
            'display_brand' => $setup->displayBrand,
            'display_last4' => $setup->displayLast4,
            'is_default' => true,
        ]);
    }

    private function createSubscriptionFixture(SubscriptionPlan $plan): Subscription
    {
        $profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);
        $paymentMethod = $this->makePaymentMethod();
        $paymentMethod->update(['customer_profile_id' => $profile->id]);

        return app(SubscriptionService::class)->createSubscription(
            tenantId: $this->tenant->id,
            customerProfileId: $profile->id,
            planId: $plan->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            paymentMethod: $paymentMethod
        );
    }

    public function test_subscription_creation_is_rejected_for_a_gateway_that_does_not_support_recurring(): void
    {
        $nonRecurringGateway = new class implements PaymentGatewayInterface
        {
            public function getProviderCode(): string
            {
                return 'non_recurring_fake';
            }

            public function supportsMethod(string $methodType): bool
            {
                return true;
            }

            public function purchase(GatewayPaymentRequest $request): GatewayPaymentResult
            {
                return GatewayPaymentResult::success('x');
            }

            public function authorize(GatewayPaymentRequest $request): GatewayPaymentResult
            {
                return GatewayPaymentResult::success('x');
            }

            public function capture(GatewayCaptureRequest $request): GatewayPaymentResult
            {
                return GatewayPaymentResult::success('x');
            }

            public function refund(GatewayRefundRequest $request): GatewayPaymentResult
            {
                return GatewayPaymentResult::success('x');
            }

            public function void(GatewayVoidRequest $request): GatewayPaymentResult
            {
                return GatewayPaymentResult::success('x');
            }
        };
        app(PaymentGatewayRegistryInterface::class)->register($nonRecurringGateway);

        $plan = $this->makePlan();
        $profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);
        $paymentMethod = CustomerPaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'customer_profile_id' => $profile->id,
            'gateway_provider_code' => 'non_recurring_fake',
            'gateway_reference' => 'ref-x',
            'is_default' => true,
        ]);

        $this->expectException(GatewayDoesNotSupportRecurringException::class);
        app(SubscriptionService::class)->createSubscription(
            tenantId: $this->tenant->id,
            customerProfileId: $profile->id,
            planId: $plan->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            paymentMethod: $paymentMethod
        );
    }

    public function test_renewal_claims_the_period_builds_one_order_and_charges_exactly_once(): void
    {
        $plan = $this->makePlan(1500);
        $subscription = $this->createSubscriptionFixture($plan);

        // Simulate time passing: the period is already due.
        $subscription->update([
            'next_billing_at' => now()->subMinute(),
            'current_period_end' => now()->subMinute(),
        ]);

        app(SubscriptionRenewalService::class)->processDueRenewals($this->tenant->id);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertTrue($subscription->current_period_end->isFuture());

        $attempts = SubscriptionRenewalAttempt::where('subscription_id', $subscription->id)->get();
        $this->assertCount(1, $attempts);
        $this->assertSame('succeeded', $attempts->first()->status->value);
        $this->assertNotNull($attempts->first()->order_id);

        $order = Order::find($attempts->first()->order_id);
        $this->assertSame(1500, $order->grand_total_minor);
    }

    public function test_a_duplicate_cron_run_for_the_same_billing_period_never_double_charges_or_double_creates_an_order(): void
    {
        $plan = $this->makePlan(1500);
        $subscription = $this->createSubscriptionFixture($plan);
        $subscription->update([
            'next_billing_at' => now()->subMinute(),
            'current_period_end' => now()->subMinute(),
        ]);

        $originalPeriodEnd = $subscription->current_period_end;

        $renewalService = app(SubscriptionRenewalService::class);
        $renewalService->processDueRenewals($this->tenant->id);

        $subscription->refresh();
        // A second, immediate cron pass: next_billing_at has ALREADY
        // advanced past "due" for a real renewal, so this call is a no-op
        // for THIS subscription — proving no duplicate processing occurs
        // even when the scheduler fires twice in quick succession.
        $renewalService->processDueRenewals($this->tenant->id);

        $this->assertSame(1, SubscriptionRenewalAttempt::where('subscription_id', $subscription->id)->count());
        $this->assertSame(1, Order::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_plan_price_change_never_alters_an_already_frozen_historical_renewal_snapshot(): void
    {
        $plan = $this->makePlan(1500);
        $subscription = $this->createSubscriptionFixture($plan);
        $subscription->update([
            'next_billing_at' => now()->subMinute(),
            'current_period_end' => now()->subMinute(),
        ]);

        app(SubscriptionRenewalService::class)->processDueRenewals($this->tenant->id);

        $attempt = SubscriptionRenewalAttempt::where('subscription_id', $subscription->id)->firstOrFail();
        $order = Order::findOrFail($attempt->order_id);
        $order->loadMissing('items');
        $item = $order->items->firstWhere('product_id', $plan->product_id);
        $this->assertSame(1500, $item->plan_snapshot['unit_price_minor']);

        // Now change the Product's live price.
        Price::where('product_id', $plan->product_id)->update(['amount_minor' => 9999]);

        $item->refresh();
        $this->assertSame(1500, $item->plan_snapshot['unit_price_minor']);
    }
}
