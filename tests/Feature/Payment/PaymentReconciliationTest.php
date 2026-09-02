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
use Modules\Payment\Services\PaymentInitiationService;
use Tests\TestCase;

class PaymentReconciliationTest extends TestCase
{
    use PaymentTestCaseTrait;

    private PaymentInitiationService $initiationService;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaymentTest();
        $this->initiationService = app(PaymentInitiationService::class);

        /** @var PaymentGatewayRegistryInterface $registry */
        $registry = app(PaymentGatewayRegistryInterface::class);
        /** @var FakePaymentGateway $fake */
        $fake = $registry->get('fake');
        $this->gateway = $fake;
        $this->gateway->reset();
    }

    public function test_timeout_after_remote_success_and_client_retry_reconciles_cleanly_with_single_monetary_execution(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 5000,
            currency: 'EUR',
            providerCode: 'fake',
            paymentMethodReference: 'pm_timeout_after_success',
            idempotencyKey: 'idem_timeout_retry'
        );

        // First attempt: remote succeeds but caller gets timeout exception
        try {
            $this->initiationService->initiatePayment($dto);
            $this->fail('Expected PaymentReconciliationPendingException was not thrown.');
        } catch (PaymentReconciliationPendingException $e) {
            $this->assertStringContainsString('unknown', $e->getMessage());
        }

        // DB state after timeout
        /** @var Payment $payment */
        $payment = Payment::where('tenant_id', $this->tenant->id)->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(PaymentStatus::PENDING->value, $payment->status);

        /** @var PaymentTransaction $tx */
        $tx = PaymentTransaction::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame(PaymentTransactionStatus::UNKNOWN->value, $tx->status);

        // Monenatry execution on remote gateway = 1
        $this->assertSame(1, $this->gateway->monetaryExecutionCount);
        $this->assertSame(0, $this->gateway->reconciliationCallCount);

        // Second attempt: retry with same idempotency key
        $result = $this->initiationService->initiatePayment($dto);

        $this->assertSame('captured', $result['status']);
        $this->assertSame('success', $result['transaction_status']);

        // Final DB state
        $paymentFresh = $payment->fresh();
        $this->assertSame(PaymentStatus::CAPTURED->value, $paymentFresh->status);
        $this->assertSame(5000, $paymentFresh->captured_amount_minor);
        $this->assertSame(OrderPaymentStatus::PAID->value, $order->fresh()->payment_status);

        // CRITICAL CONCURRENCY INVARIANT:
        // Monetary executions remained 1 (no double charge!)
        // Reconciliation was invoked once.
        $this->assertSame(1, $this->gateway->monetaryExecutionCount);
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
        // Persisted status remains UNKNOWN (NOT still_pending)
        $this->assertSame(PaymentTransactionStatus::UNKNOWN->value, $tx->status);
        $this->assertSame(1, $this->gateway->monetaryExecutionCount);
    }
}
