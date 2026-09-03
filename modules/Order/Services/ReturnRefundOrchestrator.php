<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Marketplace\Contracts\VendorPayableSubledgerServiceInterface;
use Modules\Order\Contracts\ReturnRefundOrchestratorInterface;
use Modules\Order\Contracts\ShippingRefundPolicyInterface;
use Modules\Order\Enums\RefundEligibilityStatus;
use Modules\Order\Enums\SellerReturnStatus;
use Modules\Order\Models\SellerReturn;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\PaymentTransaction;
use Modules\Payment\Services\PaymentRefundService;

class ReturnRefundOrchestrator implements ReturnRefundOrchestratorInterface
{
    public function __construct(
        private readonly PaymentRefundService $paymentRefundService,
        private readonly VendorPayableSubledgerServiceInterface $vendorPayableSubledger,
        private readonly ShippingRefundPolicyInterface $shippingRefundPolicy
    ) {}

    public function finalizeRefund(int $tenantId, int $sellerReturnId): SellerReturn
    {
        return DB::transaction(function () use ($tenantId, $sellerReturnId): SellerReturn {
            /** @var SellerReturn $sellerReturn */
            $sellerReturn = SellerReturn::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $sellerReturnId)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotency check 1: already completed
            if ($sellerReturn->refund_status === 'completed' && $sellerReturn->payment_refund_transaction_id !== null) {
                return $sellerReturn;
            }

            if ($sellerReturn->refund_eligibility_status !== RefundEligibilityStatus::ELIGIBLE->value) {
                throw new InvalidArgumentException("SellerReturn [{$sellerReturnId}] is not eligible for refund.");
            }

            // Phase-13 customer refund formula:
            //   customer_refund_minor = merchandise_refund - discount_reversal
            //                          + tax_refund + approved_shipping_refund
            // net_customer_refund_minor already holds merchandise - discount + tax
            // (see DecimalReturnAllocationService); the shipping term is resolved
            // through the explicit ShippingRefundPolicyInterface seam (Phase-13
            // default: NOT_REFUNDABLE_BY_DEFAULT, i.e. 0 unless a future policy
            // explicitly authorizes otherwise). This never touches vendor payable
            // debit, which is computed independently and excludes tax and shipping.
            $approvedShippingRefundMinor = $this->shippingRefundPolicy->approvedShippingRefundMinor($sellerReturn);
            $totalCustomerRefundMinor = $sellerReturn->net_customer_refund_minor + $approvedShippingRefundMinor;

            if ($totalCustomerRefundMinor <= 0) {
                throw new InvalidArgumentException("SellerReturn [{$sellerReturnId}] has zero refundable amount.");
            }

            $sellerReturn->refund_shipping_minor = $approvedShippingRefundMinor;

            // Persist refund_operation_uuid before external remote call
            if ($sellerReturn->refund_operation_uuid === null) {
                $sellerReturn->refund_operation_uuid = (string) Str::uuid();
                $sellerReturn->save();
            }

            $sellerOrder = $sellerReturn->sellerOrder;
            $order = $sellerOrder->order;

            /** @var Payment $payment */
            $payment = Payment::query()
                ->where('tenant_id', $tenantId)
                ->where('order_id', $order->id)
                ->firstOrFail();

            // Execute Payment refund using durable idempotency key = refund_operation_uuid
            $refundRes = $this->paymentRefundService->refund(
                tenantId: $tenantId,
                paymentUuid: $payment->uuid,
                amountMinor: $totalCustomerRefundMinor,
                idempotencyKey: $sellerReturn->refund_operation_uuid,
                metadata: [
                    'seller_return_id' => $sellerReturn->id,
                    'seller_return_uuid' => $sellerReturn->uuid,
                    'seller_order_id' => $sellerOrder->id,
                ]
            );

            /** @var PaymentTransaction $tx */
            $tx = PaymentTransaction::query()
                ->where('tenant_id', $tenantId)
                ->where('uuid', $refundRes['transaction_uuid'])
                ->firstOrFail();

            if ($tx->status !== 'success') {
                throw new InvalidArgumentException("Payment refund transaction failed with status [{$tx->status}].");
            }

            // Record Marketplace refund adjustment if vendor order
            if ($sellerReturn->seller_type === 'vendor' && $sellerReturn->vendor_id !== null) {
                $vendorGrossReversalMinor = $sellerReturn->refund_subtotal_minor - $sellerReturn->refund_discount_reversal_minor;
                $this->vendorPayableSubledger->accrueRefundAdjustment(
                    tenantId: $tenantId,
                    vendorId: $sellerReturn->vendor_id,
                    orderItemId: null,
                    sourceType: 'seller_return',
                    sourceUuid: $sellerReturn->uuid,
                    currency: $order->currency,
                    amountMinor: $vendorGrossReversalMinor,
                    commissionMinor: $sellerReturn->vendor_commission_reversal_minor,
                    storeId: $order->store_id
                );
            }

            // Finalize SellerReturn
            $sellerReturn->payment_refund_transaction_id = $tx->id;
            $sellerReturn->refund_status = 'completed';
            $sellerReturn->refund_eligibility_status = RefundEligibilityStatus::REFUNDED->value;
            $sellerReturn->status = SellerReturnStatus::COMPLETED->value;
            $sellerReturn->refund_finalized_at = now();
            $sellerReturn->completed_at = now();
            $sellerReturn->save();

            return $sellerReturn;
        });
    }
}
