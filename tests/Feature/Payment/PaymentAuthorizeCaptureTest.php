<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Illuminate\Support\Facades\Event;
use Modules\Order\Enums\PaymentStatus as OrderPaymentStatus;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Events\PaymentAuthorized;
use Modules\Payment\Events\PaymentCaptured;
use Modules\Payment\Exceptions\InvalidPaymentTransitionException;
use Modules\Payment\Exceptions\OverCaptureException;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\PaymentCaptureService;
use Modules\Payment\Services\PaymentInitiationService;
use Tests\TestCase;

class PaymentAuthorizeCaptureTest extends TestCase
{
    use PaymentTestCaseTrait;

    private PaymentInitiationService $initiationService;

    private PaymentCaptureService $captureService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaymentTest();
        $this->initiationService = app(PaymentInitiationService::class);
        $this->captureService = app(PaymentCaptureService::class);
    }

    public function test_two_step_authorization_sets_payment_and_order_authorized(): void
    {
        Event::fake([PaymentAuthorized::class]);

        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: false,
            idempotencyKey: 'auth_test_001'
        );

        $result = $this->initiationService->initiatePayment($dto);

        $this->assertSame('authorized', $result['status']);
        $this->assertSame('success', $result['transaction_status']);

        /** @var Payment $payment */
        $payment = Payment::where('tenant_id', $this->tenant->id)->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(PaymentStatus::AUTHORIZED->value, $payment->status);
        $this->assertSame(10000, $payment->authorized_amount_minor);
        $this->assertSame(0, $payment->captured_amount_minor);

        $this->assertSame(OrderPaymentStatus::AUTHORIZED->value, $order->fresh()->payment_status);
        $this->assertSame('placed', $order->fresh()->order_status);

        Event::assertDispatched(PaymentAuthorized::class);
    }

    public function test_partial_capture_keeps_payment_and_order_authorized(): void
    {
        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: false,
            idempotencyKey: 'auth_test_partial'
        );

        $initResult = $this->initiationService->initiatePayment($dto);

        // Capture 4000 out of 10000
        $captureResult = $this->captureService->capture(
            tenantId: $this->tenant->id,
            paymentUuid: $initResult['payment_uuid'],
            amountMinor: 4000,
            idempotencyKey: 'cap_part_001'
        );

        $this->assertSame('authorized', $captureResult['status']);
        $this->assertSame(4000, $captureResult['captured_amount_minor']);

        /** @var Payment $payment */
        $payment = Payment::where('tenant_id', $this->tenant->id)->where('uuid', $initResult['payment_uuid'])->firstOrFail();
        $this->assertSame(PaymentStatus::AUTHORIZED->value, $payment->status);
        $this->assertSame(4000, $payment->captured_amount_minor);

        // Order payment_status MUST remain authorized
        $this->assertSame(OrderPaymentStatus::AUTHORIZED->value, $order->fresh()->payment_status);
    }

    public function test_second_partial_capture_completes_obligation_and_transitions_to_captured(): void
    {
        Event::fake([PaymentCaptured::class]);

        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: false
        );

        $initResult = $this->initiationService->initiatePayment($dto);

        // First capture: 4000
        $this->captureService->capture(
            tenantId: $this->tenant->id,
            paymentUuid: $initResult['payment_uuid'],
            amountMinor: 4000,
            idempotencyKey: 'cap_part_a'
        );

        // Second capture: remaining 6000
        $finalCapture = $this->captureService->capture(
            tenantId: $this->tenant->id,
            paymentUuid: $initResult['payment_uuid'],
            amountMinor: 6000,
            idempotencyKey: 'cap_part_b'
        );

        $this->assertSame('captured', $finalCapture['status']);
        $this->assertSame(10000, $finalCapture['captured_amount_minor']);

        /** @var Payment $payment */
        $payment = Payment::where('tenant_id', $this->tenant->id)->where('uuid', $initResult['payment_uuid'])->firstOrFail();
        $this->assertSame(PaymentStatus::CAPTURED->value, $payment->status);
        $this->assertSame(10000, $payment->captured_amount_minor);

        // Order payment_status must project to paid
        $this->assertSame(OrderPaymentStatus::PAID->value, $order->fresh()->payment_status);
        $this->assertSame('placed', $order->fresh()->order_status);

        Event::assertDispatched(PaymentCaptured::class);
    }

    public function test_over_capture_fails_closed(): void
    {
        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: false
        );

        $initResult = $this->initiationService->initiatePayment($dto);

        $this->expectException(OverCaptureException::class);

        // Attempt to capture 10001
        $this->captureService->capture(
            tenantId: $this->tenant->id,
            paymentUuid: $initResult['payment_uuid'],
            amountMinor: 10001
        );
    }

    public function test_capture_on_pending_payment_throws_invalid_transition(): void
    {
        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        // Create payment directly in pending without authorization
        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 10000,
            'currency' => 'EUR',
            'status' => PaymentStatus::PENDING->value,
        ]);

        $this->expectException(InvalidPaymentTransitionException::class);

        $this->captureService->capture(
            tenantId: $this->tenant->id,
            paymentUuid: $payment->uuid,
            amountMinor: 5000
        );
    }
}
