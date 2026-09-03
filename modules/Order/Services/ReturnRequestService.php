<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Order\Contracts\ReturnRequestServiceInterface;
use Modules\Order\Enums\RefundEligibilityStatus;
use Modules\Order\Enums\ReturnRequestStatus;
use Modules\Order\Enums\SellerReturnStatus;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\ReturnItem;
use Modules\Order\Models\ReturnRequest;
use Modules\Order\Models\SellerOrder;
use Modules\Order\Models\SellerReturn;

class ReturnRequestService implements ReturnRequestServiceInterface
{
    public function __construct(
        private readonly DecimalReturnAllocationService $returnAllocationService
    ) {}

    public function createReturnRequest(
        int $tenantId,
        int $orderId,
        ?int $customerId,
        array $items,
        ?string $customerNote = null
    ): ReturnRequest {
        if (empty($items)) {
            throw new InvalidArgumentException('Return request must contain at least one item.');
        }

        return DB::transaction(function () use ($tenantId, $orderId, $customerId, $items, $customerNote): ReturnRequest {
            /** @var Order $order */
            $order = Order::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $orderId)
                ->firstOrFail();

            if ($customerId !== null && $order->user_id !== null && $order->user_id !== $customerId) {
                throw new InvalidArgumentException('IDOR: Order does not belong to specified customer.');
            }

            $order->loadMissing('sellerOrders.items');
            if ($order->sellerOrders->isEmpty()) {
                throw new InvalidArgumentException("Order [{$orderId}] has not been materialized into SellerOrders.");
            }

            // Map order_item_id -> SellerOrder
            $itemToSellerOrder = [];
            foreach ($order->sellerOrders as $so) {
                foreach ($so->items as $soi) {
                    $itemToSellerOrder[$soi->order_item_id] = $so;
                }
            }

            // Create ReturnRequest Header
            $rmaNumber = 'RMA-'.$order->order_number.'-'.strtoupper(Str::random(4));
            /** @var ReturnRequest $returnRequest */
            $returnRequest = ReturnRequest::create([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'store_id' => $order->store_id,
                'order_id' => $order->id,
                'rma_number' => $rmaNumber,
                'customer_id' => $customerId ?? $order->user_id,
                'overall_status' => ReturnRequestStatus::REQUESTED->value,
                'customer_note' => $customerNote,
            ]);

            // Partition items by SellerOrder
            $partitionedItems = [];
            foreach ($items as $itemData) {
                $orderItemId = (int) $itemData['order_item_id'];
                $qtyRequested = (string) $itemData['quantity'];

                /** @var OrderItem $orderItem */
                $orderItem = OrderItem::query()
                    ->where('tenant_id', $tenantId)
                    ->where('order_id', $orderId)
                    ->where('id', $orderItemId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Check cumulative approved quantity
                $existingApprovedQty = ReturnItem::query()
                    ->where('tenant_id', $tenantId)
                    ->where('order_item_id', $orderItemId)
                    ->sum('quantity_approved');

                $maxAvailable = BigDecimal::of((string) $orderItem->quantity)->minus(BigDecimal::of((string) $existingApprovedQty));
                if (BigDecimal::of($qtyRequested)->compareTo($maxAvailable) > 0) {
                    throw new InvalidArgumentException(
                        "Requested quantity [{$qtyRequested}] exceeds returnable quantity [{$maxAvailable}] for OrderItem [{$orderItemId}]."
                    );
                }

                $so = $itemToSellerOrder[$orderItemId] ?? null;
                if ($so === null) {
                    throw new InvalidArgumentException("OrderItem [{$orderItemId}] does not belong to any SellerOrder.");
                }

                $partitionedItems[$so->id][] = [
                    'order_item' => $orderItem,
                    'quantity' => $qtyRequested,
                    'condition' => $itemData['condition'] ?? 'unopened',
                    'reason' => $itemData['reason'] ?? 'customer_return',
                ];
            }

            // Create SellerReturns and ReturnItems
            foreach ($partitionedItems as $sellerOrderId => $lines) {
                /** @var SellerOrder $sellerOrder */
                $sellerOrder = $order->sellerOrders->firstWhere('id', $sellerOrderId);
                $sellerRmaNumber = $rmaNumber.'-'.($sellerOrder->seller_type === 'platform' ? 'PLT' : 'V'.$sellerOrder->vendor_id);

                /** @var SellerReturn $sellerReturn */
                $sellerReturn = SellerReturn::create([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'return_request_id' => $returnRequest->id,
                    'seller_order_id' => $sellerOrder->id,
                    'seller_type' => $sellerOrder->seller_type,
                    'vendor_id' => $sellerOrder->vendor_id,
                    'seller_rma_number' => $sellerRmaNumber,
                    'status' => SellerReturnStatus::REQUESTED->value,
                    'refund_eligibility_status' => RefundEligibilityStatus::PENDING->value,
                    'reason_code' => $lines[0]['reason'],
                ]);

                foreach ($lines as $line) {
                    ReturnItem::create([
                        'tenant_id' => $tenantId,
                        'seller_return_id' => $sellerReturn->id,
                        'order_item_id' => $line['order_item']->id,
                        'quantity_requested' => $line['quantity'],
                        'quantity_approved' => '0.00000000',
                        'quantity_received' => '0.00000000',
                        'condition' => $line['condition'],
                        'restock_action' => 'restock',
                        'action' => 'refund',
                    ]);
                }
            }

            return $returnRequest->load('sellerReturns.items');
        });
    }

    public function approveReturnItem(
        int $tenantId,
        int $sellerReturnId,
        int $orderItemId,
        string $quantityToApprove
    ): SellerReturn {
        return DB::transaction(function () use ($tenantId, $sellerReturnId, $orderItemId, $quantityToApprove): SellerReturn {
            /** @var SellerReturn $sellerReturn */
            $sellerReturn = SellerReturn::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $sellerReturnId)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var ReturnItem $returnItem */
            $returnItem = ReturnItem::query()
                ->where('tenant_id', $tenantId)
                ->where('seller_return_id', $sellerReturn->id)
                ->where('order_item_id', $orderItemId)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var OrderItem $orderItem */
            $orderItem = OrderItem::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $orderItemId)
                ->lockForUpdate()
                ->firstOrFail();

            // Total approved across all other returns
            $otherApproved = (string) ReturnItem::query()
                ->where('tenant_id', $tenantId)
                ->where('order_item_id', $orderItemId)
                ->where('id', '!=', $returnItem->id)
                ->sum('quantity_approved');

            $currentApprovedOnThisItem = (string) $returnItem->quantity_approved;
            $previouslyApprovedTotal = (string) BigDecimal::of($otherApproved)->plus(BigDecimal::of($currentApprovedOnThisItem));

            $allocation = $this->returnAllocationService->calculateItemAllocation(
                orderItem: $orderItem,
                previouslyApprovedQty: $previouslyApprovedTotal,
                quantityToApprove: $quantityToApprove
            );

            // Update ReturnItem approved quantity
            $newApprovedOnThisItem = (string) BigDecimal::of($currentApprovedOnThisItem)->plus(BigDecimal::of($quantityToApprove));
            $returnItem->update([
                'quantity_approved' => $newApprovedOnThisItem,
            ]);

            // Update SellerReturn economic totals
            $sellerReturn->update([
                'refund_subtotal_minor' => $sellerReturn->refund_subtotal_minor + $allocation['refund_subtotal_minor'],
                'refund_discount_reversal_minor' => $sellerReturn->refund_discount_reversal_minor + $allocation['refund_discount_reversal_minor'],
                'refund_tax_minor' => $sellerReturn->refund_tax_minor + $allocation['refund_tax_minor'],
                'net_customer_refund_minor' => $sellerReturn->net_customer_refund_minor + $allocation['net_customer_refund_minor'],
                'vendor_payable_debit_minor' => $sellerReturn->vendor_payable_debit_minor + $allocation['vendor_payable_debit_minor'],
                'vendor_commission_reversal_minor' => $sellerReturn->vendor_commission_reversal_minor + $allocation['vendor_commission_reversal_minor'],
                'refund_eligibility_status' => RefundEligibilityStatus::ELIGIBLE->value,
                'status' => SellerReturnStatus::APPROVED->value,
                'approved_at' => now(),
            ]);

            $fresh = $sellerReturn->fresh(['items']);

            return $fresh instanceof SellerReturn ? $fresh : $sellerReturn;
        });
    }
}
