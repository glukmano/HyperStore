<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Core\Channels\Models\Channel;
use App\Core\Channels\Models\StoreChannel;
use App\Core\Markets\Models\Market;
use App\Core\ReferenceData\Models\Currency;
use App\Core\Stores\Models\Store;
use App\Core\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\B2B\Enums\CompanyStatus;
use Modules\B2B\Enums\CompanyUserRole;
use Modules\B2B\Enums\QuoteStatus;
use Modules\B2B\Exceptions\CompanyAuthorizationException;
use Modules\B2B\Exceptions\QuoteCheckoutCouponNotAllowedException;
use Modules\B2B\Models\Company;
use Modules\B2B\Models\CompanyUser;
use Modules\B2B\Models\QuoteLine;
use Modules\B2B\Services\QuoteService;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Tests\TestCase;

class QuoteCheckoutIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Store $store;

    private Market $market;

    private Channel $channel;

    private Company $company;

    private User $owner;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Currency::firstOrCreate(['code' => 'CHF'], ['name' => 'Swiss Franc', 'symbol' => 'CHF', 'decimals' => 2, 'is_active' => true]);

        $this->tenant = Tenant::create(['name' => 'Quote Tenant', 'slug' => 'quote-tenant', 'status' => 'active']);
        $this->store = Store::create(['tenant_id' => $this->tenant->id, 'code' => 'Q_S1', 'name' => 'Store', 'slug' => 'q-s1', 'status' => 'active']);
        $this->market = Market::create(['tenant_id' => $this->tenant->id, 'code' => 'CH', 'name' => 'Switzerland', 'default_currency_code' => 'CHF', 'default_locale_code' => 'en', 'is_active' => true]);
        $this->channel = Channel::create(['name' => 'Web', 'handle' => 'web-'.uniqid(), 'is_active' => true]);
        StoreChannel::create(['store_id' => $this->store->id, 'channel_id' => $this->channel->id, 'is_active' => true]);

        TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'STD_TAX', 'name' => 'Standard Tax', 'is_default' => true]);

        $this->company = Company::create(['tenant_id' => $this->tenant->id, 'name' => 'Acme', 'status' => CompanyStatus::Active]);
        $this->owner = User::factory()->create();
        $this->buyer = User::factory()->create();
        CompanyUser::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'user_id' => $this->owner->id, 'role' => CompanyUserRole::Owner]);
        CompanyUser::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'user_id' => $this->buyer->id, 'role' => CompanyUserRole::Buyer]);
    }

    private function makeProduct(string $sku, int $priceMinor): Product
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => $sku,
            'name' => $sku,
            'slug' => strtolower($sku),
            'product_type' => 'physical',
            'status' => 'active',
        ]);

        $priceBook = PriceBook::create(['tenant_id' => $this->tenant->id, 'code' => 'STD-'.$sku, 'name' => 'Standard', 'currency' => 'CHF', 'status' => 'active', 'priority' => 1]);
        Price::create(['tenant_id' => $this->tenant->id, 'price_book_id' => $priceBook->id, 'product_id' => $product->id, 'amount_minor' => $priceMinor, 'currency' => 'CHF', 'status' => 'active']);

        return $product;
    }

    public function test_accepting_a_quote_freezes_the_server_resolved_negotiated_price_through_checkout(): void
    {
        $product = $this->makeProduct('Q-PROD-1', 5000); // normal price 50.00 CHF

        $quoteService = app(QuoteService::class);
        $quote = $quoteService->submitRfq($this->company, $this->buyer, 'CHF', [
            ['product_id' => $product->id, 'variant_id' => null, 'quantity' => '2'],
        ]);

        $quoteLine = $quote->lines()->first();
        $quoteService->quotePrices($quote, $this->owner, [$quoteLine->id => 3000]); // negotiated 30.00 CHF

        // Only owner/approver may accept — buyer cannot self-approve.
        $this->expectException(CompanyAuthorizationException::class);
        $quoteService->acceptAndBuildCart($quote, $this->buyer, new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->buyer->id,
        ));
    }

    public function test_negotiated_price_flows_through_checkout_and_freezes_onto_the_order(): void
    {
        $product = $this->makeProduct('Q-PROD-2', 5000);

        $quoteService = app(QuoteService::class);
        $quote = $quoteService->submitRfq($this->company, $this->buyer, 'CHF', [
            ['product_id' => $product->id, 'variant_id' => null, 'quantity' => '2'],
        ]);
        $quoteLine = $quote->lines()->first();
        $quoteService->quotePrices($quote, $this->owner, [$quoteLine->id => 3000]);

        $cart = $quoteService->acceptAndBuildCart($quote, $this->owner, new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->owner->id,
        ));

        $cartLine = $cart->lines()->first();
        $this->assertSame($quoteLine->id, $cartLine->quote_line_id);

        $orchestrator = app(CheckoutOrchestratorInterface::class);
        $session = $orchestrator->createFromCart($cart);
        $orchestrator->setCustomerData($session, new CheckoutCustomerData('buyer@example.com', 'A', 'B'));
        $session = $orchestrator->setAddresses($session, new CheckoutAddress('A B', ['Street 1'], 'Zurich', 'CH', postalCode: '8001'));

        // 2 * 30.00 CHF negotiated = 60.00 CHF, NOT 2 * 50.00 = 100.00 CHF.
        $this->assertSame(6000, $session->pricing_snapshot['subtotal_minor']);

        // Server-authoritative: no client input can supply a price override.
        $this->assertArrayNotHasKey('price_overrides', $session->toArray());
    }

    public function test_two_quote_lines_for_the_same_product_with_different_negotiated_prices_never_cross_map(): void
    {
        $product = $this->makeProduct('Q-PROD-3', 5000);

        $quoteService = app(QuoteService::class);
        $quote = $quoteService->submitRfq($this->company, $this->buyer, 'CHF', [
            ['product_id' => $product->id, 'variant_id' => null, 'quantity' => '1'],
            ['product_id' => $product->id, 'variant_id' => null, 'quantity' => '1'],
        ]);

        $lines = $quote->lines()->orderBy('id')->get();
        $quoteService->quotePrices($quote, $this->owner, [
            $lines[0]->id => 1000,
            $lines[1]->id => 2000,
        ]);

        $cart = $quoteService->acceptAndBuildCart($quote, $this->owner, new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->owner->id,
        ));

        $cartLines = $cart->lines()->orderBy('id')->get();
        $this->assertSame($lines[0]->id, $cartLines[0]->quote_line_id);
        $this->assertSame($lines[1]->id, $cartLines[1]->quote_line_id);
        $this->assertNotSame($cartLines[0]->quote_line_id, $cartLines[1]->quote_line_id);

        $priceForLine0 = QuoteLine::find($cartLines[0]->quote_line_id)->negotiated_unit_price_minor;
        $priceForLine1 = QuoteLine::find($cartLines[1]->quote_line_id)->negotiated_unit_price_minor;
        $this->assertSame(1000, $priceForLine0);
        $this->assertSame(2000, $priceForLine1);
    }

    public function test_accepted_quote_checkout_rejects_coupon_application(): void
    {
        $product = $this->makeProduct('Q-PROD-4', 5000);

        $quoteService = app(QuoteService::class);
        $quote = $quoteService->submitRfq($this->company, $this->buyer, 'CHF', [
            ['product_id' => $product->id, 'variant_id' => null, 'quantity' => '1'],
        ]);
        $quoteLine = $quote->lines()->first();
        $quoteService->quotePrices($quote, $this->owner, [$quoteLine->id => 4000]);

        $cart = $quoteService->acceptAndBuildCart($quote, $this->owner, new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->owner->id,
        ));

        $orchestrator = app(CheckoutOrchestratorInterface::class);
        $session = $orchestrator->createFromCart($cart);

        $this->expectException(QuoteCheckoutCouponNotAllowedException::class);
        $orchestrator->applyCoupon($session, 'ANYCODE');
    }

    public function test_quote_lifecycle_reaches_accepted_status(): void
    {
        $product = $this->makeProduct('Q-PROD-5', 5000);
        $quoteService = app(QuoteService::class);
        $quote = $quoteService->submitRfq($this->company, $this->buyer, 'CHF', [
            ['product_id' => $product->id, 'variant_id' => null, 'quantity' => '1'],
        ]);
        $this->assertSame(QuoteStatus::Submitted, $quote->status);

        $quoteService->quotePrices($quote, $this->owner, [$quote->lines()->first()->id => 4000]);
        $this->assertSame(QuoteStatus::Quoted, $quote->fresh()->status);

        $quoteService->acceptAndBuildCart($quote->fresh(), $this->owner, new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'CHF',
            userId: $this->owner->id,
        ));
        $this->assertSame(QuoteStatus::Accepted, $quote->fresh()->status);
    }
}
