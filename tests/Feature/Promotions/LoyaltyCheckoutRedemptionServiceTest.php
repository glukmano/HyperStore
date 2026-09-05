<?php

declare(strict_types=1);

namespace Tests\Feature\Promotions;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Currency;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Customers\Models\CustomerProfile;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Promotions\Contracts\LoyaltyCheckoutRedemptionServiceInterface;
use Modules\Promotions\Exceptions\InsufficientLoyaltyPointsException;
use Modules\Promotions\Models\LoyaltyProgram;
use Modules\Promotions\Models\LoyaltyProgramCurrencyRule;
use Modules\Promotions\Models\Promotion;
use Modules\Promotions\Services\LoyaltyService;
use Tests\TestCase;

class LoyaltyCheckoutRedemptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private CustomerProfile $profile;

    private LoyaltyCheckoutRedemptionServiceInterface $redemptionService;

    private LoyaltyService $loyaltyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Currency::firstOrCreate(['code' => 'CHF'], ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_active' => true]);

        $this->tenant = Tenant::create(['name' => 'Redemption Tenant', 'slug' => 'redemption-tenant']);
        $user = User::factory()->create();
        $this->profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id]);

        $program = LoyaltyProgram::create(['tenant_id' => $this->tenant->id, 'name' => 'P', 'is_active' => true]);
        LoyaltyProgramCurrencyRule::create([
            'tenant_id' => $this->tenant->id,
            'loyalty_program_id' => $program->id,
            'currency' => 'CHF',
            'minor_units_per_point' => 100,
            'point_redemption_value_minor' => 5, // 1 point = 0.05 CHF
            'is_active' => true,
        ]);

        $this->loyaltyService = app(LoyaltyService::class);
        $this->loyaltyService->earnPoints($this->profile, 1000, 'test', 'seed-points');

        $this->redemptionService = app(LoyaltyCheckoutRedemptionServiceInterface::class);
    }

    public function test_redeeming_points_mints_a_single_use_coupon_worth_the_frozen_value(): void
    {
        $coupon = $this->redemptionService->redeemForCheckout($this->profile, $this->tenant->id, 200, 'CHF', 'session-a');

        $this->assertSame(1, $coupon->usage_limit);
        $this->assertSame('active', $coupon->status);
        $this->assertSame(800, $this->loyaltyService->getAvailableBalance($this->profile));

        $promotion = Promotion::findOrFail($coupon->promotion_id);
        $action = $promotion->actions()->where('action_type', 'fixed_discount')->firstOrFail();
        $this->assertSame(1000, $action->parameters['amount_minor']); // 200 points * 5 minor = 1000
    }

    public function test_redemption_rejects_a_tampered_request_exceeding_the_real_balance(): void
    {
        $this->expectException(InsufficientLoyaltyPointsException::class);

        $this->redemptionService->redeemForCheckout($this->profile, $this->tenant->id, 999999, 'CHF', 'session-b');
    }

    public function test_redemption_is_idempotent_per_checkout_session(): void
    {
        $first = $this->redemptionService->redeemForCheckout($this->profile, $this->tenant->id, 100, 'CHF', 'session-c');
        $balanceAfterFirst = $this->loyaltyService->getAvailableBalance($this->profile);

        $second = $this->redemptionService->redeemForCheckout($this->profile, $this->tenant->id, 100, 'CHF', 'session-c');

        $this->assertSame($first->id, $second->id);
        $this->assertSame($balanceAfterFirst, $this->loyaltyService->getAvailableBalance($this->profile));
    }

    public function test_cancelling_an_unused_redemption_reverses_the_points_and_deactivates_the_coupon(): void
    {
        $coupon = $this->redemptionService->redeemForCheckout($this->profile, $this->tenant->id, 300, 'CHF', 'session-d');
        $this->assertSame(700, $this->loyaltyService->getAvailableBalance($this->profile));

        $this->redemptionService->cancelForCheckout('session-d', $this->tenant->id);

        $this->assertSame(1000, $this->loyaltyService->getAvailableBalance($this->profile));
        $this->assertSame('expired', $coupon->fresh()->status);

        // Cancelling twice must not double-credit.
        $this->redemptionService->cancelForCheckout('session-d', $this->tenant->id);
        $this->assertSame(1000, $this->loyaltyService->getAvailableBalance($this->profile));
    }

    public function test_redeemed_points_reduce_the_real_checkout_total_through_the_existing_coupon_pipeline(): void
    {
        $store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'LR_S1', 'name' => 'Store', 'slug' => 'lr-s1', 'status' => 'active']);
        $market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'CH', 'name' => 'Switzerland', 'default_currency_code' => 'CHF', 'default_locale_code' => 'en', 'is_active' => true]);
        $channel = Channel::create(['name' => 'Web', 'handle' => 'web-'.Str::random(6), 'is_active' => true]);
        StoreChannel::create(['store_id' => $store->id, 'channel_id' => $channel->id, 'is_active' => true]);

        TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'STD_TAX', 'name' => 'Standard Tax', 'is_default' => true]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'LR-PROD',
            'name' => 'Product',
            'slug' => 'lr-prod',
            'product_type' => 'physical',
            'status' => 'active',
        ]);

        $priceBook = PriceBook::create(['tenant_id' => $this->tenant->id, 'code' => 'STD', 'name' => 'Standard', 'currency' => 'CHF', 'status' => 'active', 'priority' => 1]);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $priceBook->id, 'product_id' => $product->id, 'amount_minor' => 5000, 'currency' => 'CHF', 'status' => 'active']);

        $cartService = app(CartServiceInterface::class);
        $orchestrator = app(CheckoutOrchestratorInterface::class);

        $cart = $cartService->getOrCreateActiveCart(new CartContext(
            tenantId: $this->tenant->id,
            storeId: $store->id,
            marketId: $market->id,
            channelId: $channel->id,
            currency: 'CHF',
            userId: $this->profile->user_id,
        ));
        $cartService->addLine($cart, new CartLineItemData(productId: $product->id, variantId: null, quantity: CartQuantity::fromInt(1)));

        $session = $orchestrator->createFromCart($cart);
        $orchestrator->setCustomerData($session, new CheckoutCustomerData('c@example.com', 'A', 'B'));
        $session = $orchestrator->setAddresses($session, new CheckoutAddress('A B', ['Street 1'], 'Zurich', 'CH', postalCode: '8001'));

        $coupon = $this->redemptionService->redeemForCheckout($this->profile, $this->tenant->id, 400, 'CHF', $session->uuid);
        $this->assertSame(600, $this->loyaltyService->getAvailableBalance($this->profile));

        $session = $orchestrator->applyCoupon($session, $coupon->code);

        $this->assertNotNull($session->promotion_snapshot);
        $this->assertSame(2000, $session->promotion_snapshot['total_discount_minor']); // 400 points * 5 minor = 2000
        $this->assertSame(600, $this->loyaltyService->getAvailableBalance($this->profile));
    }
}
