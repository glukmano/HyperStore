<?php

declare(strict_types=1);

namespace Modules\GiftCards\Listeners;

use Illuminate\Support\Facades\Notification;
use Modules\GiftCards\Notifications\GiftCardIssuedNotification;
use Modules\GiftCards\Services\GiftCardService;
use Modules\Order\Models\Order;
use Modules\Payment\Events\PaymentCaptured;
use Throwable;

/**
 * Owner Delta C.31: buying a Gift Card is never a Loyalty/Affiliate-
 * commissionable event (double-incentive risk) — this listener only ever
 * issues the card and emails the code, nothing else.
 */
class IssueGiftCardOnPaymentCaptured
{
    public function __construct(
        private readonly GiftCardService $giftCardService
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
            $order->loadMissing('items', 'user');
            foreach ($order->items as $item) {
                if ($item->product_type_snapshot !== 'gift-card') {
                    continue;
                }

                $quantity = max(1, (int) $item->quantity);
                for ($i = 0; $i < $quantity; $i++) {
                    $sourceUuid = "order_item:{$item->id}:".($i + 1);
                    $result = $this->giftCardService->issue(
                        $order->tenant_id,
                        $order->currency,
                        (int) $item->unit_price_minor,
                        $sourceUuid
                    );

                    if ($order->user !== null) {
                        Notification::send($order->user, new GiftCardIssuedNotification(
                            $result['plaintextCode'],
                            $order->currency,
                            (int) $item->unit_price_minor
                        ));
                    }
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
