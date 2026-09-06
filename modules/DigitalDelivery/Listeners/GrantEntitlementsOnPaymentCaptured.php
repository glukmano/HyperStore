<?php

declare(strict_types=1);

namespace Modules\DigitalDelivery\Listeners;

use Modules\DigitalDelivery\Contracts\DigitalEntitlementGrantHookInterface;
use Modules\Order\Models\Order;
use Modules\Payment\Events\PaymentCaptured;
use Throwable;

/**
 * Entitlements/license keys are granted only once payment has genuinely
 * succeeded — mirroring Loyalty's EarnLoyaltyPointsOnOrderPaidListener
 * precedent, never at Order-creation time itself (a payment that
 * subsequently fails must not have already handed out a license key).
 */
class GrantEntitlementsOnPaymentCaptured
{
    public function __construct(
        private readonly DigitalEntitlementGrantHookInterface $hook
    ) {}

    public function handle(PaymentCaptured $event): void
    {
        $transaction = $event->transaction;
        if (! in_array($transaction->operation_type, ['purchase', 'capture', 'zero_total_settlement'], true)) {
            return;
        }

        /** @var Order|null $order */
        $order = Order::where('id', $event->payment->order_id)->first();
        if ($order === null) {
            return;
        }

        try {
            $this->hook->grantForOrder($order);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
