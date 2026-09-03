<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Illuminate\Support\Facades\Queue;
use Modules\Order\Enums\PaymentStatus as OrderPaymentStatus;
use Modules\Order\Events\OrderCancelled;
use Modules\Payment\Contracts\PaymentGatewayRegistryInterface;
use Modules\Payment\DTOs\InitiatePaymentDTO;
use Modules\Payment\Enums\PaymentStatus;
use Modules\Payment\Jobs\ProcessPaymentVoidJob;
use Modules\Payment\Models\Payment;
use Modules\Payment\Providers\FakePaymentGateway;
use Modules\Payment\Services\PaymentCancellationService;
use Modules\Payment\Services\PaymentInitiationService;
use Tests\TestCase;

class PaymentOrderCancelledAsyncTest extends TestCase
{
    use PaymentTestCaseTrait;

    private PaymentInitiationService $initiationService;

    private PaymentCancellationService $cancellationService;

    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaymentTest();
        $this->initiationService = app(PaymentInitiationService::class);
        $this->cancellationService = app(PaymentCancellationService::class);

        /** @var PaymentGatewayRegistryInterface $registry */
        $registry = app(PaymentGatewayRegistryInterface::class);
        /** @var FakePaymentGateway $fake */
        $fake = $registry->get('fake');
        $this->gateway = $fake;
        $this->gateway->reset();
    }

    public function test_order_cancelled_on_authorized_payment_does_not_synchronously_call_gateway_void(): void
    {
        Queue::fake([ProcessPaymentVoidJob::class]);

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

        // Gateway monetary count is 1 for authorization
        $this->assertSame(1, $this->gateway->monetaryExecutionCount);

        // Dispatch OrderCancelled
        event(new OrderCancelled($order, 'Customer cancellation'));

        // CRITICAL INVARIANT: Zero inline gateway void calls!
        $this->assertSame(1, $this->gateway->monetaryExecutionCount);

        // Assert job was dispatched out-of-band
        Queue::assertPushed(ProcessPaymentVoidJob::class, function (ProcessPaymentVoidJob $job) use ($paymentUuid): bool {
            return $job->tenantId === $this->tenant->id && $job->paymentUuid === $paymentUuid;
        });

        // Now manually execute the job out-of-band to prove it performs the void
        $job = new ProcessPaymentVoidJob($this->tenant->id, $paymentUuid, 'Customer cancellation');
        $job->handle($this->cancellationService);

        /** @var Payment $payment */
        $payment = Payment::where('uuid', $paymentUuid)->firstOrFail();
        $this->assertSame(PaymentStatus::CANCELLED->value, $payment->status);
        $this->assertSame(OrderPaymentStatus::VOIDED->value, $order->fresh()->payment_status);

        // Exactly one void execution occurred when job was processed
        $this->assertSame(2, $this->gateway->monetaryExecutionCount);
    }

    public function test_order_cancelled_on_pending_payment_cancels_immediately_with_zero_gateway_calls(): void
    {
        Queue::fake([ProcessPaymentVoidJob::class]);

        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR');

        /** @var Payment $payment */
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'order_id' => $order->id,
            'amount_minor' => 5000,
            'currency' => 'EUR',
            'status' => PaymentStatus::PENDING->value,
        ]);

        event(new OrderCancelled($order, 'Cancelled before checkout'));

        // Invariant: Immediate local cancellation, zero gateway calls, no job needed
        $this->assertSame(PaymentStatus::CANCELLED->value, $payment->fresh()->status);
        $this->assertSame(OrderPaymentStatus::VOIDED->value, $order->fresh()->payment_status);
        $this->assertSame(0, $this->gateway->monetaryExecutionCount);
        Queue::assertNotPushed(ProcessPaymentVoidJob::class);
    }
}
