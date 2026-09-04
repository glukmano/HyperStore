<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Livewire\Storefront\CheckoutPage;
use Livewire\Livewire;
use Modules\Payment\Models\Payment;
use Tests\Feature\Payment\PaymentTestCaseTrait;
use Tests\TestCase;

/**
 * Proves the Phase-15 completion-delta checkout payment step (Owner Delta item 4):
 * CheckoutPage::retryPayment()/initiatePaymentForPlacedOrder() calls the real, existing
 * Modules\Payment\Services\PaymentInitiationService::initiatePayment() against a real
 * Order — no new payment business logic, no fabricated gateway behavior. Uses the same
 * fixture trait as the Payment module's own service-level tests
 * (tests/Feature/Payment/PaymentPurchaseTest.php) so the Order shape matches exactly
 * what PaymentInitiationService already expects.
 */
class CheckoutPaymentStepTest extends TestCase
{
    use PaymentTestCaseTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaymentTest();
    }

    public function test_retry_payment_initiates_a_real_payment_and_redirects_to_confirmation_on_capture(): void
    {
        $order = $this->createOrder(grandTotalMinor: 4200, currency: 'EUR', orderStatus: 'placed', paymentStatus: 'pending');

        Livewire::test(CheckoutPage::class)
            ->set('placedOrderId', $order->id)
            ->set('placedOrderTenantId', $order->tenant_id)
            ->set('placedOrderNumber', $order->order_number)
            ->set('placedOrderAmountMinor', $order->grand_total_minor)
            ->set('placedOrderCurrency', $order->currency)
            ->set('paymentMethodType', 'card')
            ->call('retryPayment')
            ->assertRedirect(route('storefront.order-confirmation', ['orderNumber' => $order->order_number]));

        $payment = Payment::where('tenant_id', $this->tenant->id)->where('order_id', $order->id)->first();

        expect($payment)->not->toBeNull();
        expect($payment->status)->toBe('captured');
        expect($payment->amount_minor)->toBe(4200);
    }

    public function test_a_real_payment_service_exception_is_caught_and_surfaces_the_failed_step_not_a_500(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR', orderStatus: 'placed', paymentStatus: 'pending');

        // PaymentInitiationService::executeInitiation() throws PaymentAmountMismatchException
        // when the DTO amount disagrees with the Order's real grand_total_minor
        // (modules/Payment/Services/PaymentInitiationService.php) — a genuine backend
        // invariant, not a fabricated failure mode. Proves CheckoutPage's catch block
        // degrades to the payment_failed step instead of a 500.
        Livewire::test(CheckoutPage::class)
            ->set('checkoutSessionId', $order->checkout_id)
            ->set('placedOrderId', $order->id)
            ->set('placedOrderTenantId', $order->tenant_id)
            ->set('placedOrderNumber', $order->order_number)
            ->set('placedOrderAmountMinor', 999999)
            ->set('placedOrderCurrency', $order->currency)
            ->call('retryPayment')
            ->assertOk()
            ->assertSet('step', 'payment_failed')
            ->assertSee('could not be initiated');

        $this->assertDatabaseMissing('payments', ['tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'status' => 'captured']);
    }
}
