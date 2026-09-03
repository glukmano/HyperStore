<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Modules\Order\Contracts\OrderPaymentSynchronizationServiceInterface;
use Modules\Order\Enums\PaymentStatus as OrderPaymentStatus;
use Modules\Order\Exceptions\InvalidOrderTransitionException;
use Tests\TestCase;

class OrderPaymentSynchronizationServiceTest extends TestCase
{
    use PaymentTestCaseTrait;

    private OrderPaymentSynchronizationServiceInterface $syncService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPaymentTest();
        $this->syncService = app(OrderPaymentSynchronizationServiceInterface::class);
    }

    public function test_valid_forward_transitions_succeed(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR', paymentStatus: 'pending');

        // pending -> authorized
        $order = $this->syncService->syncPaymentStatus($order->tenant_id, $order->id, OrderPaymentStatus::AUTHORIZED, 'Authorized');
        $this->assertSame('authorized', $order->payment_status);

        // authorized -> paid
        $order = $this->syncService->syncPaymentStatus($order->tenant_id, $order->id, OrderPaymentStatus::PAID, 'Captured');
        $this->assertSame('paid', $order->payment_status);

        // paid -> refunded
        $order = $this->syncService->syncPaymentStatus($order->tenant_id, $order->id, OrderPaymentStatus::REFUNDED, 'Refunded');
        $this->assertSame('refunded', $order->payment_status);
    }

    public function test_idempotent_same_status_transition_is_noop(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR', paymentStatus: 'paid');
        $originalVersion = $order->version;

        $synced = $this->syncService->syncPaymentStatus($order->tenant_id, $order->id, OrderPaymentStatus::PAID, 'Re-confirm paid');
        $this->assertSame('paid', $synced->payment_status);
        $this->assertSame($originalVersion, $synced->version);
    }

    public function test_stale_authorization_cannot_regress_paid_status(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR', paymentStatus: 'paid');

        $this->expectException(InvalidOrderTransitionException::class);
        $this->expectExceptionMessage('INVALID_ORDER_TRANSITION: Cannot transition payment from [paid] to [authorized].');

        $this->syncService->syncPaymentStatus($order->tenant_id, $order->id, OrderPaymentStatus::AUTHORIZED, 'Stale auth response');
    }

    public function test_stale_reconciliation_cannot_regress_refunded_to_paid(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR', paymentStatus: 'refunded');

        $this->expectException(InvalidOrderTransitionException::class);
        $this->expectExceptionMessage('INVALID_ORDER_TRANSITION: Cannot transition payment from [refunded] to [paid].');

        $this->syncService->syncPaymentStatus($order->tenant_id, $order->id, OrderPaymentStatus::PAID, 'Stale capture reconciliation');
    }

    public function test_stale_provider_result_cannot_regress_voided_to_authorized(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR', paymentStatus: 'voided');

        $this->expectException(InvalidOrderTransitionException::class);
        $this->expectExceptionMessage('INVALID_ORDER_TRANSITION: Cannot transition payment from [voided] to [authorized].');

        $this->syncService->syncPaymentStatus($order->tenant_id, $order->id, OrderPaymentStatus::AUTHORIZED, 'Stale auth');
    }

    public function test_stale_provider_result_cannot_regress_voided_to_paid(): void
    {
        $order = $this->createOrder(grandTotalMinor: 5000, currency: 'EUR', paymentStatus: 'voided');

        $this->expectException(InvalidOrderTransitionException::class);
        $this->expectExceptionMessage('INVALID_ORDER_TRANSITION: Cannot transition payment from [voided] to [paid].');

        $this->syncService->syncPaymentStatus($order->tenant_id, $order->id, OrderPaymentStatus::PAID, 'Stale purchase');
    }
}
