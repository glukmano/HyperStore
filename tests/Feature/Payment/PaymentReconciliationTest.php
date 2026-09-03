<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Modules\Order\Enums\PaymentStatus as OrderPaymentStatus;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentTransactionStatus;
use Modules\Payment\Exceptions\PaymentReconciliationPendingException;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Providers\FakePaymentGateway;
use Modules\Payment\Services\PaymentCancellationService;
use Modules\Payment\Services\PaymentCaptureService;
use Modules\Payment\Services\PaymentInitiationService;
use Modules\Payment\Services\PaymentRefundService;
use Modules\Payment\Services\PaymentTransactionReconciliationService;
use Tests\TestCase;

class PaymentReconciliationTest extends TestCase
{
    use PaymentTestCaseTrait;

    private PaymentInitiationService $initiationService;

    private PaymentCaptureService $captureService;

    private PaymentRefundService $refundService;

    private PaymentCancellationService $cancellationService;

    private PaymentTransactionReconciliationService $reconciliationService;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaymentTest();
        $this->initiationService = app(PaymentInitiationService::class);
        $this->captureService = app(PaymentCaptureService::class);
        $this->refundService = app(PaymentRefundService::class);
        $this->cancellationService = app(PaymentCancellationService::class);
        $this->reconciliationService = app(PaymentTransactionReconciliationService::class);

        /** @var PaymentGatewayRegistryInterface $registry */
        $registry = app(PaymentGatewayRegistryInterface::class);
        /** @var FakePaymentGateway $fake */
        $fake = $registry->get('fake');
        $this->gateway = $fake;
        $this->gateway->reset();
    }

    public function test_purchase_timeout_retry_reconciles_cleanly_with_single_monetary_execution(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 5000,
            currency: 'EUR',
            providerCode: 'fake',
            paymentMethodReference: 'pm_timeout_after_success',
            captureImmediately: true,
            idempotencyKey: 'idem_purchase_timeout_retry'
        );

        // Attempt 1: remote succeeds, but local throws timeout
        try {
            $this->initiationService->initiatePayment($dto);
            $this->fail('Expected PaymentReconciliationPendingException was not thrown.');
        } catch (PaymentReconciliationPendingException $e) {
            $this->assertStringContainsString('indeterminate', $e->getMessage());
        }

        /** @var Payment $payment */
        $payment = Payment::where('tenant_id', $this->tenant->id)->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(PaymentStatus::PENDING->value, $payment->status);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame(PaymentTransactionStatus::UNKNOWN->value, $tx->status);

        $this->assertSame(1, $this->gateway->monetaryExecutionCount);
        $this->assertSame(0, $this->gateway->reconciliationCallCount);

        // Attempt 2: retry with same idempotency key
        $result = $this->initiationService->initiatePayment($dto);

        $this->assertSame('captured', $result['status']);
        $this->assertSame('success', $result['transaction_status']);

        $this->assertSame(PaymentStatus::CAPTURED->value, $payment->fresh()->status);
        $this->assertSame(5000, $payment->fresh()->captured_amount_minor);
        $this->assertSame(OrderPaymentStatus::PAID->value, $order->fresh()->payment_status);

        // Gateway monetary call count remains 1; reconciliation called once
        $this->assertSame(1, $this->gateway->monetaryExecutionCount);
        $this->assertSame(1, $this->gateway->reconciliationCallCount);
    }

    public function test_authorize_timeout_retry_reconciles_cleanly_with_single_monetary_execution(): void
    {
        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            paymentMethodReference: 'pm_timeout_after_success',
            captureImmediately: false,
            idempotencyKey: 'idem_auth_timeout_retry'
        );

        try {
            $this->initiationService->initiatePayment($dto);
            $this->fail('Expected PaymentReconciliationPendingException');
        } catch (PaymentReconciliationPendingException) {
            // expected
        }

        /** @var Payment $payment */
        $payment = Payment::where('tenant_id', $this->tenant->id)->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(PaymentStatus::PENDING->value, $payment->status);

        $this->assertSame(1, $this->gateway->monetaryExecutionCount);

        // Retry authorization
        $result = $this->initiationService->initiatePayment($dto);

        $this->assertSame('authorized', $result['status']);
        $this->assertSame('success', $result['transaction_status']);

        $this->assertSame(PaymentStatus::AUTHORIZED->value, $payment->fresh()->status);
        $this->assertSame(10000, $payment->fresh()->authorized_amount_minor);
        $this->assertSame(0, $payment->fresh()->captured_amount_minor);
        $this->assertSame(OrderPaymentStatus::AUTHORIZED->value, $order->fresh()->payment_status);

        $this->assertSame(1, $this->gateway->monetaryExecutionCount);
        $this->assertSame(1, $this->gateway->reconciliationCallCount);
    }

    public function test_capture_timeout_retry_reconciles_cleanly_and_increments_captured_amount_once(): void
    {
        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        // Authorize 10000
        $init = $this->initiationService->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: false
        ));
        $paymentUuid = $init['payment_uuid'];

        $this->assertSame(1, $this->gateway->monetaryExecutionCount);

        // Force gateway capture to timeout after remote success
        $this->gateway->forcedNextOutcome = 'timeout_after_success';

        try {
            $this->captureService->capture(
                tenantId: $this->tenant->id,
                paymentUuid: $paymentUuid,
                amountMinor: 4000,
                idempotencyKey: 'cap_timeout_idem_key'
            );
            $this->fail('Expected PaymentReconciliationPendingException');
        } catch (PaymentReconciliationPendingException) {
            // expected
        }

        /** @var Payment $payment */
        $payment = Payment::where('uuid', $paymentUuid)->firstOrFail();
        // Payment captured_amount_minor is still 0 before reconciliation
        $this->assertSame(0, $payment->captured_amount_minor);

        /** @var PaymentTransaction $capTx */
        $capTx = PaymentTransaction::where('payment_id', $payment->id)->where('operation_type', 'capture')->firstOrFail();
        $this->assertSame(PaymentTransactionStatus::UNKNOWN->value, $capTx->status);

        // Monetary execution count was 1 (auth) + 1 (capture) = 2
        $this->assertSame(2, $this->gateway->monetaryExecutionCount);

        // Reset forced outcome so reconciliation can query truth
        $this->gateway->forcedNextOutcome = null;

        // Retry the capture with identical idempotency key
        $retryResult = $this->captureService->capture(
            tenantId: $this->tenant->id,
            paymentUuid: $paymentUuid,
            amountMinor: 4000,
            idempotencyKey: 'cap_timeout_idem_key'
        );

        $this->assertSame('authorized', $retryResult['status']);
        $this->assertSame('success', $retryResult['transaction_status']);

        // CRITICAL INVARIANT: captured amount is exactly 4000, NOT 8000!
        $this->assertSame(4000, $payment->fresh()->captured_amount_minor);
        $this->assertSame(PaymentStatus::AUTHORIZED->value, $payment->fresh()->status);
        $this->assertSame(OrderPaymentStatus::AUTHORIZED->value, $order->fresh()->payment_status);

        // Monetary execution count MUST REMAIN 2 (no double charge!)
        $this->assertSame(2, $this->gateway->monetaryExecutionCount);
        $this->assertSame(1, $this->gateway->reconciliationCallCount);
    }

    public function test_refund_timeout_retry_reconciles_cleanly_and_increments_refunded_amount_once(): void
    {
        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        $init = $this->initiationService->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: true
        ));
        $paymentUuid = $init['payment_uuid'];

        $this->assertSame(1, $this->gateway->monetaryExecutionCount);

        // Force gateway refund to timeout after remote success
        $this->gateway->forcedNextOutcome = 'timeout_after_success';

        try {
            $this->refundService->refund(
                tenantId: $this->tenant->id,
                paymentUuid: $paymentUuid,
                amountMinor: 3000,
                idempotencyKey: 'ref_timeout_idem_key'
            );
            $this->fail('Expected PaymentReconciliationPendingException');
        } catch (PaymentReconciliationPendingException) {
            // expected
        }

        /** @var Payment $payment */
        $payment = Payment::where('uuid', $paymentUuid)->firstOrFail();
        $this->assertSame(0, $payment->refunded_amount_minor);

        /** @var PaymentTransaction $refTx */
        $refTx = PaymentTransaction::where('payment_id', $payment->id)->where('operation_type', 'refund')->firstOrFail();
        $this->assertSame(PaymentTransactionStatus::UNKNOWN->value, $refTx->status);

        // Monetary execution count: 1 purchase + 1 refund = 2
        $this->assertSame(2, $this->gateway->monetaryExecutionCount);

        // Reset forced outcome
        $this->gateway->forcedNextOutcome = null;

        // Retry refund with same idempotency key
        $retryResult = $this->refundService->refund(
            tenantId: $this->tenant->id,
            paymentUuid: $paymentUuid,
            amountMinor: 3000,
            idempotencyKey: 'ref_timeout_idem_key'
        );

        $this->assertSame('partially_refunded', $retryResult['status']);
        $this->assertSame('success', $retryResult['transaction_status']);

        // Invariant: refunded amount is exactly 3000, NOT 6000!
        $this->assertSame(3000, $payment->fresh()->refunded_amount_minor);
        $this->assertSame(PaymentStatus::PARTIALLY_REFUNDED->value, $payment->fresh()->status);
        $this->assertSame(OrderPaymentStatus::PAID->value, $order->fresh()->payment_status);

        // Zero additional monetary replay!
        $this->assertSame(2, $this->gateway->monetaryExecutionCount);
        $this->assertSame(1, $this->gateway->reconciliationCallCount);
    }

    public function test_void_timeout_retry_reconciles_cleanly_and_cancels_payment_once(): void
    {
        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        $init = $this->initiationService->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: false
        ));
        $paymentUuid = $init['payment_uuid'];

        $this->assertSame(1, $this->gateway->monetaryExecutionCount);

        // Force void timeout after success
        $this->gateway->forcedNextOutcome = 'timeout_after_success';

        try {
            $this->cancellationService->cancel(
                tenantId: $this->tenant->id,
                paymentUuid: $paymentUuid,
                reason: 'Customer requested void',
                idempotencyKey: 'void_timeout_idem_key'
            );
            $this->fail('Expected PaymentReconciliationPendingException');
        } catch (PaymentReconciliationPendingException) {
            // expected
        }

        /** @var Payment $payment */
        $payment = Payment::where('uuid', $paymentUuid)->firstOrFail();
        $this->assertSame(PaymentStatus::AUTHORIZED->value, $payment->status);

        /** @var PaymentTransaction $voidTx */
        $voidTx = PaymentTransaction::where('payment_id', $payment->id)->where('operation_type', 'void')->firstOrFail();
        $this->assertSame(PaymentTransactionStatus::UNKNOWN->value, $voidTx->status);

        $this->assertSame(2, $this->gateway->monetaryExecutionCount);

        $this->gateway->forcedNextOutcome = null;

        // Retry void
        $retryResult = $this->cancellationService->cancel(
            tenantId: $this->tenant->id,
            paymentUuid: $paymentUuid,
            reason: 'Customer requested void',
            idempotencyKey: 'void_timeout_idem_key'
        );

        $this->assertSame('cancelled', $retryResult['status']);
        $this->assertSame('success', $retryResult['transaction_status']);

        $this->assertSame(PaymentStatus::CANCELLED->value, $payment->fresh()->status);
        $this->assertSame(OrderPaymentStatus::VOIDED->value, $order->fresh()->payment_status);

        $this->assertSame(2, $this->gateway->monetaryExecutionCount);
        $this->assertSame(1, $this->gateway->reconciliationCallCount);
    }

    public function test_reconciliation_still_pending_leaves_transaction_unknown_without_double_charge(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 5000,
            currency: 'EUR',
            providerCode: 'fake',
            paymentMethodReference: 'pm_timeout_after_success',
            idempotencyKey: 'idem_timeout_still_pending'
        );

        try {
            $this->initiationService->initiatePayment($dto);
        } catch (PaymentReconciliationPendingException) {
            // expected
        }

        // Force next reconciliation outcome to still_pending
        $this->gateway->forcedNextOutcome = 'reconciliation_still_pending';

        try {
            $this->initiationService->initiatePayment($dto);
            $this->fail('Expected PaymentReconciliationPendingException on still_pending reconciliation.');
        } catch (PaymentReconciliationPendingException) {
            // expected
        }

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::where('tenant_id', $this->tenant->id)->latest('id')->firstOrFail();
        $this->assertSame(PaymentTransactionStatus::UNKNOWN->value, $tx->status);
        $this->assertSame(1, $this->gateway->monetaryExecutionCount);
    }

    public function test_reconciliation_success_called_twice_applies_financial_effect_once_only(): void
    {
        $order = $this->createOrder(grandTotalMinor: 10000, currency: 'EUR');

        $init = $this->initiationService->initiatePayment(new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 10000,
            currency: 'EUR',
            providerCode: 'fake',
            captureImmediately: false
        ));
        $payment = Payment::where('uuid', $init['payment_uuid'])->firstOrFail();

        // Create an unknown capture transaction
        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::create([
            'tenant_id' => $this->tenant->id,
            'payment_id' => $payment->id,
            'operation_type' => 'capture',
            'status' => PaymentTransactionStatus::UNKNOWN->value,
            'amount_minor' => 4000,
            'currency' => 'EUR',
            'provider_code' => 'fake',
            'provider_idempotency_key' => 'hyp_tx_test_double_reconcile',
        ]);

        // Seed provider record
        $this->gateway->saveRecord('hyp_tx_test_double_reconcile', [
            'status' => 'captured',
            'reference' => 'cap_reconcile_double_test',
            'amount' => 4000,
            'currency' => 'EUR',
        ]);

        // First reconciliation call:
        $this->reconciliationService->reconcile($tx, $payment);
        $this->assertSame(4000, $payment->fresh()->captured_amount_minor);
        $this->assertSame(PaymentTransactionStatus::SUCCESS->value, $tx->fresh()->status);

        // Second reconciliation call directly on the same transaction:
        $this->reconciliationService->reconcile($tx->fresh(), $payment->fresh());

        // Invariant: financial effect MUST NOT be applied a second time!
        $this->assertSame(4000, $payment->fresh()->captured_amount_minor);
    }
}
