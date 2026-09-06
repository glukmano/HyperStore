<?php

declare(strict_types=1);

namespace Modules\Wallet\Listeners;

use Modules\Order\Models\Order;
use Modules\Payment\Events\PaymentCaptured;
use Modules\Wallet\Contracts\StoreValueCheckoutHookInterface;

/**
 * Owner Delta §16: a Store Value hold converts to a final capture ONLY once
 * the Order/payment actually succeeds — never at Order-creation time
 * itself (Order creation precedes payment: OrderCreationService::
 * executeOrderCreationTransaction() runs first, PaymentInitiationService
 * charges the (possibly Store-Value-reduced) amountDueMinor second).
 * PaymentCaptured fires for BOTH a real gateway capture and the existing
 * zero-total-order branch (a fully-Store-Value-covered Order), so this one
 * listener handles every case uniformly.
 */
class ConvertStoreValueHoldOnPaymentCaptured
{
    public function __construct(
        private readonly StoreValueCheckoutHookInterface $hook
    ) {}

    public function handle(PaymentCaptured $event): void
    {
        $payment = $event->payment;
        $transaction = $event->transaction;

        if (! in_array($transaction->operation_type, ['purchase', 'capture', 'zero_total_settlement'], true)) {
            return;
        }

        /** @var Order|null $order */
        $order = Order::where('id', $payment->order_id)->first();
        if ($order === null) {
            return;
        }

        $this->hook->convertHoldsToCaptureForOrder($order, (int) $transaction->amount_minor, (string) $transaction->uuid);
    }
}
