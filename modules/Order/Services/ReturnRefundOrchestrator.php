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
use Modules\Payment\Models\PaymentTransaction;
use Modules\Wallet\Services\StoreValueRefundService;

/**
 * Phase-22 Owner Delta §5: the real refund call site now dispatches through
 * the tender-aware StoreValueRefundService (itself delegating per-tender
 * application to the tender-neutral TenderRefundDispatchService) instead of
 * calling PaymentRefundService directly — a necessary fix, since this RMA
 * refund path pre-dated Phase-21's tender-allocation model and had never
 * been wired to it, meaning a POS cash-tendered (or Store-Value-tendered)
 * Order refunded through Returns would otherwise have been misrouted
 * entirely to the external gateway.
 */
class ReturnRefundOrchestrator implements ReturnRefundOrchestratorInterface
{
    public function __construct(
        private readonly StoreValueRefundService $storeValueRefundService,
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

            // Idempotency check 1: already completed. payment_refund_transaction_id
            // is only ever populated for an external_gateway tender slice — a
            // pure-cash or pure-Store-Value refund legitimately never sets it,
            // so refund_status alone is the authoritative completion signal.
            if ($sellerReturn->refund_status === 'completed') {
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

            // Tender-aware refund dispatch — refunds preserve the ORIGINAL
            // tender allocation (external_gateway, wallet, store_credit,
            // gift_card, cash), never assuming a single full-gateway
            // refund. Idempotency key = refund_operation_uuid.
            $tenderResults = $this->storeValueRefundService->refundOrder(
                $order,
                $totalCustomerRefundMinor,
                (string) $sellerReturn->refund_operation_uuid
            );

            foreach ($tenderResults as $result) {
                if ($result['tender_type'] === 'external_gateway' && ($result['transaction_status'] ?? null) !== null) {
                    if ($result['transaction_status'] !== 'success') {
                        throw new InvalidArgumentException("Payment refund transaction failed with status [{$result['transaction_status']}].");
                    }

                    /** @var PaymentTransaction|null $tx */
                    $tx = PaymentTransaction::query()
                        ->where('tenant_id', $tenantId)
                        ->where('uuid', $result['transaction_uuid'])
                        ->first();

                    if ($tx !== null) {
                        $sellerReturn->payment_refund_transaction_id = $tx->id;
                    }
                }
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
