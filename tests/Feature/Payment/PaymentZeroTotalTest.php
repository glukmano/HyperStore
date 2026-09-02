<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Modules\Order\Enums\PaymentStatus as OrderPaymentStatus;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Enums\PaymentOperationType;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Enums\PaymentTransactionStatus;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Providers\FakePaymentGateway;
use Modules\Payment\Services\PaymentInitiationService;
use Tests\TestCase;

class PaymentZeroTotalTest extends TestCase
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

    public function test_zero_total_order_settles_internally_with_zero_gateway_invocations(): void
    {
        $order = $this->createOrder(grandTotalMinor: 0, currency: 'EUR');

        $dto = new InitiatePaymentDTO(
            tenantId: $this->tenant->id,
            orderId: $order->id,
            amountMinor: 0,
            currency: 'EUR',
            providerCode: 'fake',
            idempotencyKey: 'zero_tot_001'
        );

        $result = $this->initiationService->initiatePayment($dto);

        $this->assertSame('captured', $result['status']);
        $this->assertSame('success', $result['transaction_status']);

        // Check DB payment aggregate
        /** @var Payment $payment */
        $payment = Payment::where('tenant_id', $this->tenant->id)->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(PaymentStatus::CAPTURED->value, $payment->status);
        $this->assertSame(0, $payment->amount_minor);
        $this->assertSame(0, $payment->captured_amount_minor);

        // Check DB transaction
        /** @var PaymentTransaction $transaction */
        $transaction = PaymentTransaction::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame(PaymentOperationType::ZERO_TOTAL_SETTLEMENT->value, $transaction->operation_type);
        $this->assertSame(PaymentTransactionStatus::SUCCESS->value, $transaction->status);
        $this->assertNull($transaction->provider_code);
        $this->assertNull($transaction->provider_reference);

        // Check Order payment_status projected to paid
        $this->assertSame(OrderPaymentStatus::PAID->value, $order->fresh()->payment_status);

        // CRITICAL INVARIANT: Zero gateway calls occurred!
        $this->assertSame(0, $this->gateway->monetaryExecutionCount);

        // Replay same request returns cached response
        $replay = $this->initiationService->initiatePayment($dto);
        $this->assertSame($result['payment_id'], $replay['payment_id']);
        $this->assertSame(0, $this->gateway->monetaryExecutionCount);
    }
}
