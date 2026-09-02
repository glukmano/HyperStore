<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Illuminate\Support\Facades\Event;
use Modules\Order\Enums\PaymentStatus as OrderPaymentStatus;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Events\PaymentActionRequired;
use Modules\Payment\Events\PaymentCaptured;
use Modules\Payment\Exceptions\OrderAlreadyCancelledException;
use Modules\Payment\Exceptions\PaymentAmountMismatchException;
use Modules\Payment\Exceptions\PaymentCurrencyMismatchException;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\PaymentInitiationService;
use Tests\TestCase;

class PaymentPurchaseTest extends TestCase
{
    use PaymentTestCaseTrait;

    private PaymentInitiationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaymentTest();
        $this->service = app(PaymentInitiationService::class);
    }

    public function test_successful_purchase_captures_payment_and_syncs_order_paid_without_confirming_order(): void
    {
        Event::fake([PaymentCaptured::class]);

        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR', orderStatus: 'placed', paymentStatus: 'pending');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 5000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: true,
            idempotencyKey: 'idem_purchase_001'
        );

        $result = $this->service->initiatePayment($dto);

        $this->assertSame('captured', $result['status']);
        $this->assertSame('success', $result['transaction_status']);

        // Check Payment aggregate in DB
        /** @var Payment $payment */
        $payment = Payment::where('tenant_id', $this->tenant->id)->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(PaymentStatus::CAPTURED->value, $payment->status);
        $this->assertSame(5000, $payment->amount_minor);
        $this->assertSame(5000, $payment->captured_amount_minor);

        // Check Order payment_status projected to paid
        $orderFresh = $order->fresh();
        $this->assertSame(OrderPaymentStatus::PAID->value, $orderFresh->payment_status);

        // Crucial invariant: order_status MUST NOT be changed to confirmed!
        $this->assertSame('placed', $orderFresh->order_status);

        Event::assertDispatched(PaymentCaptured::class);
    }

    public function test_amount_mismatch_fails_closed(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 4000, // mismatch
            currency: 'EUR',
            providerCode: 'fake'
        );

        $this->expectException(PaymentAmountMismatchException::class);
        $this->service->initiatePayment($dto);
    }

    public function test_currency_mismatch_fails_closed(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 5000,
            currency: 'USD', // mismatch
            providerCode: 'fake'
        );

        $this->expectException(PaymentCurrencyMismatchException::class);
        $this->service->initiatePayment($dto);
    }

    public function test_payment_initiation_on_cancelled_order_fails_closed(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR', orderStatus: 'cancelled');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 5000,
            currency: 'EUR',
            providerCode: 'fake'
        );

        $this->expectException(OrderAlreadyCancelledException::class);
        $this->service->initiatePayment($dto);
    }

    public function test_declined_attempt_marks_transaction_failure_but_payment_remains_pending_for_retry(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        // First attempt: decline
        $dto1 = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 5000,
            currency: 'EUR',
            providerCode: 'fake',
            paymentMethodReference: 'pm_decline',
            idempotencyKey: 'idem_attempt_1'
        );

        $result1 = $this->service->initiatePayment($dto1);

        $this->assertSame('pending', $result1['status']);
        $this->assertSame('failure', $result1['transaction_status']);

        /** @var Payment $payment */
        $payment = Payment::where('tenant_id', $this->tenant->id)->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(PaymentStatus::PENDING->value, $payment->status);
        $this->assertSame(0, $payment->captured_amount_minor);
        $this->assertSame(OrderPaymentStatus::PENDING->value, $order->fresh()->payment_status);

        // Second attempt: retry with new card and succeed
        $dto2 = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 5000,
            currency: 'EUR',
            providerCode: 'fake',
            paymentMethodReference: 'pm_success',
            idempotencyKey: 'idem_attempt_2'
        );

        $result2 = $this->service->initiatePayment($dto2);

        $this->assertSame('captured', $result2['status']);
        $this->assertSame('success', $result2['transaction_status']);

        $paymentFresh = $payment->fresh();
        $this->assertSame(PaymentStatus::CAPTURED->value, $paymentFresh->status);
        $this->assertSame(5000, $paymentFresh->captured_amount_minor);
        $this->assertCount(2, $paymentFresh->transactions);
        $this->assertSame(OrderPaymentStatus::PAID->value, $order->fresh()->payment_status);
    }

    public function test_action_required_transitions_transaction_and_dispatches_event(): void
    {
        Event::fake([PaymentActionRequired::class]);

        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 5000,
            currency: 'EUR',
            providerCode: 'fake',
            paymentMethodReference: 'pm_3ds',
            idempotencyKey: 'idem_action_3ds'
        );

        $result = $this->service->initiatePayment($dto);

        $this->assertSame('action_required', $result['transaction_status']);
        $this->assertSame('redirect_url', $result['action_type']);
        $this->assertStringContainsString('3ds', $result['action_payload']['url']);

        Event::assertDispatched(PaymentActionRequired::class);
    }
}
