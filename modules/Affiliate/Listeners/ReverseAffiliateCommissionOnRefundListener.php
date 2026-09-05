<?php

declare(strict_types=1);

namespace Modules\Affiliate\Listeners;

use Modules\Affiliate\Enums\AffiliateConversionStatus;
use Modules\Affiliate\Models\AffiliateConversion;
use Modules\Affiliate\Services\AffiliateConversionReversalService;
use Modules\Payment\Events\PaymentPartiallyRefunded;
use Modules\Payment\Events\PaymentRefunded;

/**
 * Owner Delta correction §5: refunds/chargebacks reverse commission via
 * idempotent compensating entries derived from the immutable conversion-item
 * snapshot — proportional to how much of the order's captured amount this
 * refund transaction represents. The refund event only carries an order-level
 * amount (no per-line refund detail exists in the Payment module today), so
 * the reversal is spread proportionally across every conversion item by its
 * own frozen commission weight — never recomputed from live prices.
 */
final class ReverseAffiliateCommissionOnRefundListener
{
    public function __construct(
        private readonly AffiliateConversionReversalService $reversalService,
    ) {}

    public function handle(PaymentRefunded|PaymentPartiallyRefunded $event): void
    {
        $payment = $event->payment;
        $transaction = $event->transaction;

        /** @var AffiliateConversion|null $conversion */
        $conversion = AffiliateConversion::where('tenant_id', $payment->tenant_id)
            ->where('order_id', $payment->order_id)
            ->where('status', AffiliateConversionStatus::Accrued->value)
            ->first();

        if ($conversion === null) {
            return;
        }

        $captured = (int) $payment->captured_amount_minor;
        if ($captured <= 0) {
            return;
        }

        $refundRatio = min(1.0, max(0.0, ((int) $transaction->amount_minor) / $captured));
        if ($refundRatio <= 0.0) {
            return;
        }

        // Idempotency key is derived from the refund PaymentTransaction's own
        // uuid, so replaying the same refund event never double-reverses.
        $this->reversalService->reverseConversion($conversion, (string) $transaction->uuid, $refundRatio);
    }
}
