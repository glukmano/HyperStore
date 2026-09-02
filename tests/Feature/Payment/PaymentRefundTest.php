<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Illuminate\Support\Facades\Event;
use Modules\Order\Enums\PaymentStatus as OrderPaymentStatus;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Events\PaymentPartiallyRefunded;
use Modules\Payment\Events\PaymentRefunded;
use Modules\Payment\Exceptions\InvalidPaymentTransitionException;
use Modules\Payment\Exceptions\OverRefundException;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\PaymentInitiationService;
use Modules\Payment\Services\PaymentRefundService;
use Tests\TestCase;

class PaymentRefundTest extends TestCase
{
    use PaymentTestCaseTrait;

    private PaymentInitiationService $initiationService;

    private PaymentRefundService $refundService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaymentTest();
        $this->initiationService = app(PaymentInitiationService::class);
        $this->refundService = app(PaymentRefundService::class);
    }

    public function test_full_refund_transitions_payment_and_order_to_refunded(): void
    {
        Event::fake([PaymentRefunded::class]);

        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        $initResult = $this->initiationService->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: true
        ));

        $refundResult = $this->refundService->refund(
            tenantId: $this->tenant->id,
            paymentUuid: $initResult['payment_uuid'],
            amountMinor: 10000,
            idempotencyKey: 'ref_full_001'
        );

        $this->assertSame('refunded', $refundResult['status']);
        $this->assertSame(10000, $refundResult['refunded_amount_minor']);

        /** @var Payment $payment */
        $payment = Payment::where('tenant_id', $this->tenant->id)->where('uuid', $initResult['payment_uuid'])->firstOrFail();
        $this->assertSame(PaymentStatus::REFUNDED->value, $payment->status);
        $this->assertSame(10000, $payment->refunded_amount_minor);

        $this->assertSame(OrderPaymentStatus::REFUNDED->value, $order->fresh()->payment_status);

        Event::assertDispatched(PaymentRefunded::class);
    }

    public function test_partial_refund_transitions_payment_to_partially_refunded_while_order_remains_paid(): void
    {
        Event::fake([PaymentPartiallyRefunded::class]);

        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        $initResult = $this->initiationService->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: true
        ));

        $refundResult = $this->refundService->refund(
            tenantId: $this->tenant->id,
            paymentUuid: $initResult['payment_uuid'],
            amountMinor: 3000,
            idempotencyKey: 'ref_partial_001'
        );

        $this->assertSame('partially_refunded', $refundResult['status']);
        $this->assertSame(3000, $refundResult['refunded_amount_minor']);

        /** @var Payment $payment */
        $payment = Payment::where('tenant_id', $this->tenant->id)->where('uuid', $initResult['payment_uuid'])->firstOrFail();
        $this->assertSame(PaymentStatus::PARTIALLY_REFUNDED->value, $payment->status);
        $this->assertSame(3000, $payment->refunded_amount_minor);

        // Crucial invariant: order payment_status remains paid!
        $this->assertSame(OrderPaymentStatus::PAID->value, $order->fresh()->payment_status);

        Event::assertDispatched(PaymentPartiallyRefunded::class);
    }

    public function test_incremental_refunds_reach_full_refund_cleanly(): void
    {
        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        $initResult = $this->initiationService->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: true
        ));

        // Refund 1: 4000
        $this->refundService->refund(
            tenantId: $this->tenant->id,
            paymentUuid: $initResult['payment_uuid'],
            amountMinor: 4000,
            idempotencyKey: 'ref_inc_1'
        );

        // Refund 2: 6000
        $res2 = $this->refundService->refund(
            tenantId: $this->tenant->id,
            paymentUuid: $initResult['payment_uuid'],
            amountMinor: 6000,
            idempotencyKey: 'ref_inc_2'
        );

        $this->assertSame('refunded', $res2['status']);
        $this->assertSame(10000, $res2['refunded_amount_minor']);

        $this->assertSame(OrderPaymentStatus::REFUNDED->value, $order->fresh()->payment_status);
    }

    public function test_over_refund_fails_closed(): void
    {
        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        $initResult = $this->initiationService->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: true
        ));

        $this->expectException(OverRefundException::class);

        // Attempt to refund 10001
        $this->refundService->refund(
            tenantId: $this->tenant->id,
            paymentUuid: $initResult['payment_uuid'],
            amountMinor: 10001
        );
    }

    public function test_refund_on_pending_payment_throws_invalid_transition(): void
    {
        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 10000,
            'currency' => 'EUR',
            'status' => PaymentStatus::PENDING->value,
        ]);

        $this->expectException(InvalidPaymentTransitionException::class);

        $this->refundService->refund(
            tenantId: $this->tenant->id,
            paymentUuid: $payment->uuid,
            amountMinor: 5000
        );
    }
}
