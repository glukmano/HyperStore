<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use Modules\Cart\Contracts\CartServiceInterface;
use Modules\Cart\ValueObjects\CartContext;
use Modules\Cart\ValueObjects\CartLineItemData;
use Modules\Cart\ValueObjects\CartQuantity;
use Modules\Catalog\Models\Product;
use Modules\Checkout\Contracts\CheckoutOrchestratorInterface;
use Modules\Checkout\DTOs\CheckoutCustomerData;
use Modules\Customers\Models\CustomerProfile;
use Modules\Order\Contracts\OrderCreationServiceInterface;
use Modules\Order\DTOs\OrderCreationDTO;
use Modules\Order\Models\OrderPaymentTenderAllocation;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Services\PaymentInitiationService;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceBook;
use Modules\Pricing\Models\TaxClass;
use Modules\Wallet\Contracts\StoreValueServiceInterface;
use Modules\Wallet\Enums\StoreValueInstrumentType;
use Tests\Feature\Ledger\LedgerTestCaseTrait;
use Tests\TestCase;

/**
 * C.25/C.27/Owner Delta §16: Store Value is applied at Checkout as a
 * reduction of amountDueMinor (never a Coupon), and Payment charges only
 * the remaining amount. amountDueMinor is capped correctly, grandTotal
 * remains the full commercial value, and the hold converts to a real
 * capture only once payment genuinely succeeds.
 */
class MixedTenderCheckoutTest extends TestCase
{
    use LedgerTestCaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLedgerTest();
        $this->provisionSystemAccounts();
        TaxClass::create(['tenant_id' => $this->tenant->id, 'code' => 'STD_TAX', 'name' => 'Standard Tax', 'is_default' => true]);
    }

    private function makeProductWithPrice(int $priceMinor): Product
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'sku' => 'MT-'.uniqid(),
            'name' => 'Mixed Tender Item',
            'slug' => 'mt-'.uniqid(),
            'product_type' => 'digital',
            'status' => 'active',
        ]);

        $priceBook = PriceBook::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'MT_PB_'.uniqid(),
            'name' => 'MT Price Book',
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

        return $product;
    }

    public function test_store_value_reduces_amount_due_but_never_grand_total(): void
    {
        $product = $this->makeProductWithPrice(5000);
        $profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);

        $storeValueService = app(StoreValueServiceInterface::class);
        $account = $storeValueService->findOrCreateAccount($this->tenant->id, $profile->id, StoreValueInstrumentType::Wallet, 'EUR');
        $storeValueService->issue($account, 2000, 'manual_adjustment', 'seed-1');

        $cart = app(CartServiceInterface::class)->getOrCreateActiveCart(new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'EUR',
            userId: $this->user->id,
        ));
        app(CartServiceInterface::class)->addLine($cart, new CartLineItemData(
            productId: $product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1)
        ));

        $orchestrator = app(CheckoutOrchestratorInterface::class);
        $session = $orchestrator->createFromCart($cart);
        $session = $orchestrator->setCustomerData($session, new CheckoutCustomerData('mt@example.com', 'M', 'T'));

        $session = $orchestrator->applyStoreValue($session, 'wallet', null, 2000);
        $this->assertSame(2000, $session->store_value_applied_minor);

        $ready = $orchestrator->markReadyForOrder($session);
        $this->assertSame(5000, $ready->totals['grand_total']);
        $this->assertSame(3000, $ready->totals['amount_due']);

        $result = app(OrderCreationServiceInterface::class)->createFromCheckout(new OrderCreationDTO(
            tenantId: $ready->tenantId,
            checkoutId: $ready->checkoutSessionId,
        ));
        $order = $result->order;

        $this->assertSame(5000, $order->grand_total_minor);
        $this->assertSame(3000, $order->amount_due_minor);

        // PaymentInitiationService must charge only amountDueMinor, not
        // grand_total_minor — this is the exact relaxed invariant.
        $response = app(PaymentInitiationService::class)->initiatePayment(new InitiatePaymentDTO(
            tenantId: $order->tenant_id,
            orderId: $order->id,
            amountMinor: 3000,
            currency: 'EUR',
            providerCode: null,
            paymentMethodType: 'card',
            paymentMethodReference: null,
            captureImmediately: true,
            idempotencyKey: null,
            metadata: [],
        ));
        $this->assertSame('captured', $response['status']);

        // The hold converts to a final capture, and both tender portions
        // are recorded in order_payment_tender_allocations.
        $allocations = OrderPaymentTenderAllocation::where('order_id', $order->id)->get();
        $this->assertSame(2000, (int) $allocations->firstWhere('tender_type', 'wallet')?->amount_minor);
        $this->assertSame(3000, (int) $allocations->firstWhere('tender_type', 'external_gateway')?->amount_minor);

        // The full 2000 seeded was applied and captured — nothing remains.
        $this->assertSame(0, $storeValueService->getAvailableBalanceMinor($account));
    }

    public function test_a_fully_store_value_covered_order_skips_the_gateway_entirely(): void
    {
        $product = $this->makeProductWithPrice(2000);
        $profile = CustomerProfile::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id]);

        $storeValueService = app(StoreValueServiceInterface::class);
        $account = $storeValueService->findOrCreateAccount($this->tenant->id, $profile->id, StoreValueInstrumentType::Wallet, 'EUR');
        $storeValueService->issue($account, 5000, 'manual_adjustment', 'seed-2');

        $cart = app(CartServiceInterface::class)->getOrCreateActiveCart(new CartContext(
            tenantId: $this->tenant->id,
            storeId: $this->store->id,
            marketId: $this->market->id,
            channelId: $this->channel->id,
            currency: 'EUR',
            userId: $this->user->id,
        ));
        app(CartServiceInterface::class)->addLine($cart, new CartLineItemData(
            productId: $product->id,
            variantId: null,
            quantity: CartQuantity::fromInt(1)
        ));

        $orchestrator = app(CheckoutOrchestratorInterface::class);
        $session = $orchestrator->createFromCart($cart);
        $session = $orchestrator->setCustomerData($session, new CheckoutCustomerData('mt2@example.com', 'M', 'T'));
        $session = $orchestrator->applyStoreValue($session, 'wallet', null, 2000);

        $ready = $orchestrator->markReadyForOrder($session);
        $this->assertSame(0, $ready->totals['amount_due']);

        $result = app(OrderCreationServiceInterface::class)->createFromCheckout(new OrderCreationDTO(
            tenantId: $ready->tenantId,
            checkoutId: $ready->checkoutSessionId,
        ));
        $order = $result->order;

        $response = app(PaymentInitiationService::class)->initiatePayment(new InitiatePaymentDTO(
            tenantId: $order->tenant_id,
            orderId: $order->id,
            amountMinor: 0,
            currency: 'EUR',
            providerCode: null,
            paymentMethodType: null,
            paymentMethodReference: null,
            captureImmediately: true,
            idempotencyKey: null,
            metadata: [],
        ));
        $this->assertSame('captured', $response['status']);

        $allocations = OrderPaymentTenderAllocation::where('order_id', $order->id)->get();
        $this->assertSame(1, $allocations->count());
        $this->assertSame('wallet', $allocations->first()->tender_type);
        $this->assertSame(3000, $storeValueService->getAvailableBalanceMinor($account));
    }
}
