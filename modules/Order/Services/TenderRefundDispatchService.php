<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Modules\Order\Models\Order;
use Modules\Order\Models\OrderPaymentTenderAllocation;
use Modules\Order\Models\RefundTenderAllocation;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\PaymentRefundService;
use Modules\POS\Contracts\PosCashRefundServiceInterface;
use Modules\Wallet\Models\StoreValueAccount;
use Modules\Wallet\Models\StoreValueEntry;
use Modules\Wallet\Services\StoreValueService;

/**
 * Phase-22 Owner Delta §5: the smallest tender-neutral refund
 * orchestration seam over the existing refund domains. Store Value
 * remains responsible only for Store Value instruments; Payment remains
 * responsible for gateway refunds; POS/Cash remains responsible for cash
 * drawer movement. This is NOT a second refund engine — it only dispatches
 * an already-computed RefundTenderAllocation to the correct existing
 * domain service by tender_type.
 */
final class TenderRefundDispatchService
{
    public function __construct(
        private readonly PaymentRefundService $paymentRefundService,
        private readonly StoreValueService $storeValueService,
    ) {}

    /**
     * @return array{tender_type: string, amount_minor: int, transaction_uuid: ?string, transaction_status: ?string}
     */
    public function dispatch(Order $order, RefundTenderAllocation $allocation): array
    {
        $transactionUuid = null;
        $transactionStatus = null;

        if ($allocation->tender_type === 'external_gateway') {
            /** @var Payment|null $payment */
            $payment = Payment::where('order_id', $order->id)->first();
            if ($payment !== null) {
                $refundRes = $this->paymentRefundService->refund(
                    tenantId: $order->tenant_id,
                    paymentUuid: (string) $payment->uuid,
                    amountMinor: (int) $allocation->amount_minor,
                    idempotencyKey: "refund_tender:{$allocation->refund_event_uuid}:external_gateway"
                );
                $transactionUuid = $refundRes['transaction_uuid'] ?? null;
                $transactionStatus = $refundRes['transaction_status'] ?? null;
            }
        } elseif ($allocation->tender_type === 'cash') {
            app(PosCashRefundServiceInterface::class)->refundCash(
                $order,
                (int) $allocation->amount_minor,
                (string) $allocation->refund_event_uuid
            );
        } else {
            $originalTender = OrderPaymentTenderAllocation::where('order_id', $order->id)
                ->where('tender_type', $allocation->tender_type)
                ->first();

            if ($originalTender !== null) {
                /** @var StoreValueEntry|null $captureEntry */
                $captureEntry = StoreValueEntry::find((int) $originalTender->source_reference);
                if ($captureEntry !== null) {
                    /** @var StoreValueAccount $account */
                    $account = StoreValueAccount::where('id', $captureEntry->store_value_account_id)->firstOrFail();

                    $this->storeValueService->refundCredit(
                        $account,
                        (int) $allocation->amount_minor,
                        "refund_tender:{$allocation->refund_event_uuid}:{$allocation->tender_type}"
                    );
                }
            }
        }

        return [
            'tender_type' => $allocation->tender_type,
            'amount_minor' => (int) $allocation->amount_minor,
            'transaction_uuid' => $transactionUuid,
            'transaction_status' => $transactionStatus,
        ];
    }
}
