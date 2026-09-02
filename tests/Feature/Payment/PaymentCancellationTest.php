<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Illuminate\Support\Facades\Event;
use Modules\Order\Enums\PaymentStatus as OrderPaymentStatus;
use Modules\Order\Events\OrderCancelled;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Events\PaymentCancelled;
use Modules\Payment\Events\PaymentReconciliationRequired;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\PaymentCancellationService;
use Modules\Payment\Services\PaymentInitiationService;
use Tests\TestCase;

class PaymentCancellationTest extends TestCase
{
    use PaymentTestCaseTrait;

    private PaymentInitiationService $initiationService;

    private PaymentCancellationService $cancellationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaymentTest();
        $this->initiationService = app(PaymentInitiationService::class);
        $this->cancellationService = app(PaymentCancellationService::class);
    }

    public function test_cancelling_pending_payment_marks_cancelled_and_order_voided(): void
    {
        Event::fake([PaymentCancelled::class]);

        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'status' => PaymentStatus::PENDING->value,
        ]);

        $result = $this->cancellationService->cancel(
            tenantId: $this->tenant->id,
            paymentUuid: $payment->uuid,
            reason: 'Customer cancelled before paying'
        );

        $this->assertSame('cancelled', $result['status']);
        $this->assertSame(PaymentStatus::CANCELLED->value, $payment->fresh()->status);
        $this->assertSame(OrderPaymentStatus::VOIDED->value, $order->fresh()->payment_status);

        Event::assertDispatched(PaymentCancelled::class);
    }

    public function test_voiding_authorized_payment_calls_gateway_and_marks_cancelled_and_voided(): void
    {
        Event::fake([PaymentCancelled::class]);

        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        $initResult = $this->initiationService->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: false
        ));

        $cancelResult = $this->cancellationService->cancel(
            tenantId: $this->tenant->id,
            paymentUuid: $initResult['payment_uuid'],
            reason: 'Customer cancelled after authorization'
        );

        $this->assertSame('cancelled', $cancelResult['status']);

        /** @var Payment $payment */
        $payment = Payment::where('tenant_id', $this->tenant->id)->where('uuid', $initResult['payment_uuid'])->firstOrFail();
        $this->assertSame(PaymentStatus::CANCELLED->value, $payment->status);
        $this->assertSame(OrderPaymentStatus::VOIDED->value, $order->fresh()->payment_status);

        Event::assertDispatched(PaymentCancelled::class);
    }

    public function test_cancellation_of_captured_payment_does_not_auto_refund_and_emits_reconciliation_required(): void
    {
        Event::fake([PaymentReconciliationRequired::class]);

        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        $initResult = $this->initiationService->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: true
        ));

        $cancelResult = $this->cancellationService->cancel(
            tenantId: $this->tenant->id,
            paymentUuid: $initResult['payment_uuid'],
            reason: 'Order cancelled after funds collected'
        );

        $this->assertTrue($cancelResult['reconciliation_required']);
        $this->assertSame('captured', $cancelResult['status']);

        /** @var Payment $payment */
        $payment = Payment::where('tenant_id', $this->tenant->id)->where('uuid', $initResult['payment_uuid'])->firstOrFail();
        $this->assertSame(PaymentStatus::CAPTURED->value, $payment->status);
        $this->assertTrue($payment->metadata['cancellation_reconciliation_required']);

        Event::assertDispatched(PaymentReconciliationRequired::class);
    }

    public function test_order_cancelled_event_listener_triggers_payment_cancellation(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'status' => PaymentStatus::PENDING->value,
        ]);

        // Dispatch OrderCancelled event directly from Order domain
        event(new OrderCancelled($order, 'Store operator cancelled order'));

        $this->assertSame(PaymentStatus::CANCELLED->value, $payment->fresh()->status);
        $this->assertSame(OrderPaymentStatus::VOIDED->value, $order->fresh()->payment_status);
    }
}
