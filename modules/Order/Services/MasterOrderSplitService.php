<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Order\Contracts\MasterOrderSplitServiceInterface;
use Modules\Order\Exceptions\InconsistentHistoricalShippingSnapshotException;
use Modules\Order\Exceptions\MissingHistoricalCommercialModelException;
use Modules\Order\Exceptions\MissingHistoricalShippingEligibilityException;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderItem;
use Modules\Order\Models\SellerOrder;
use Modules\Order\Models\SellerOrderItem;

class MasterOrderSplitService implements MasterOrderSplitServiceInterface
{
    public function __construct(
        private readonly JointShippingAllocationService $shippingAllocator
    ) {}

    /**
     * @return Collection<int, SellerOrder>
     */
    public function splitOrder(Order $order): Collection
    {
        // 1. Check existing materialized SellerOrders (Idempotent return)
        /** @var Collection<int, SellerOrder> $existing */
        $existing = SellerOrder::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('order_id', $order->id)
            ->with('items')
            ->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        // 2. Validate historical commercial model snapshot (Fail Closed)
        if ($order->commercial_model_snapshot === null || trim($order->commercial_model_snapshot) === '') {
            throw MissingHistoricalCommercialModelException::forOrder($order->id);
        }

        // 3. Load order items
        $order->loadMissing('items');
        /** @var Collection<int, OrderItem> $items */
        $items = $order->items;

        // 4. Validate historical shipping eligibility on lines (Fail Closed if shipping > 0)
        $hasShippingTotal = $order->shipping_total_minor > 0;
        foreach ($items as $item) {
            if ($hasShippingTotal && $item->requires_shipping_snapshot === null) {
                throw MissingHistoricalShippingEligibilityException::forOrderItem($item->id);
            }
        }

        // 5. Validate shipping snapshot arithmetic
        $shippingSnapshot = $order->shipping_snapshot ?? [];
        $snapshotFinal = (int) ($shippingSnapshot['final_amount'] ?? $shippingSnapshot['cost_minor'] ?? $order->shipping_total_minor);
        $snapshotOrig = (int) ($shippingSnapshot['original_amount'] ?? $snapshotFinal);
        $snapshotDisc = (int) ($shippingSnapshot['breakdown']['promotionDiscount']['minor_amount']
            ?? $shippingSnapshot['breakdown']['promotionDiscount']
            ?? ($snapshotOrig - $snapshotFinal));

        if ($snapshotFinal !== $order->shipping_total_minor) {
            throw InconsistentHistoricalShippingSnapshotException::arithmeticMismatch(
                $order->id,
                "Snapshot final shipping [{$snapshotFinal}] does not match order total [{$order->shipping_total_minor}]."
            );
        }

        if ($snapshotOrig !== ($snapshotFinal + $snapshotDisc)) {
            throw InconsistentHistoricalShippingSnapshotException::arithmeticMismatch(
                $order->id,
                "Snapshot original shipping [{$snapshotOrig}] != final [{$snapshotFinal}] + discount [{$snapshotDisc}]."
            );
        }

        // 6. Partition OrderItems by seller identity (strictly historical)
        $partitions = [];
        foreach ($items as $item) {
            $key = $item->vendor_id === null ? 'platform' : 'vendor:'.$item->vendor_id;

            if (! isset($partitions[$key])) {
                $partitions[$key] = [
                    'seller_type' => $item->vendor_id === null ? 'platform' : 'vendor',
                    'vendor_id' => $item->vendor_id,
                    'subtotal_minor' => 0,
                    'discount_minor' => 0,
                    'tax_minor' => 0,
                    'commission_total_minor' => 0,
                    'eligible_subtotal_minor' => 0,
                    'has_shipping_eligible_items' => false,
                    'items' => [],
                ];
            }

            $partitions[$key]['subtotal_minor'] += $item->subtotal_minor;
            $partitions[$key]['discount_minor'] += $item->discount_minor;
            $partitions[$key]['tax_minor'] += $item->tax_minor;
            $partitions[$key]['commission_total_minor'] += (int) ($item->commission_amount_minor ?? 0);
            $partitions[$key]['items'][] = $item;

            // Zero-shipping safe exemption: if shipping is 0, line does not need to be marked eligible
            if ($item->requires_shipping_snapshot === true) {
                $partitions[$key]['has_shipping_eligible_items'] = true;
                $partitions[$key]['eligible_subtotal_minor'] += $item->subtotal_minor;
            }
        }

        // 7. Allocate shipping across partitions using Joint Allocation
        $shippingAllocations = $this->shippingAllocator->allocate(
            $partitions,
            $snapshotFinal,
            $snapshotDisc,
            $snapshotOrig
        );

        // 8. Execute materialization inside database transaction with concurrency race catch
        try {
            return DB::transaction(function () use ($order, $partitions, $shippingAllocations): Collection {
                // Re-check inside transaction lock
                /** @var Collection<int, SellerOrder> $alreadySplit */
                $alreadySplit = SellerOrder::query()
                    ->where('tenant_id', $order->tenant_id)
                    ->where('order_id', $order->id)
                    ->with('items')
                    ->get();

                if ($alreadySplit->isNotEmpty()) {
                    return $alreadySplit;
                }

                $createdSellerOrders = new Collection;
                $sumSubtotal = 0;
                $sumDiscount = 0;
                $sumTax = 0;
                $sumShipFinal = 0;
                $sumTotal = 0;

                // Deterministic ordering of partitions by key
                ksort($partitions);

                foreach ($partitions as $key => $part) {
                    $shipFinal = $shippingAllocations[$key]['shipping_final_minor'];
                    $shipDisc = $shippingAllocations[$key]['shipping_discount_minor'];
                    $shipOrig = $shippingAllocations[$key]['shipping_original_minor'];

                    $soSubtotal = $part['subtotal_minor'];
                    $soDiscount = $part['discount_minor'];
                    $soTax = $part['tax_minor'];
                    $soTotal = $soSubtotal - $soDiscount + $soTax + $shipFinal;
                    $soCommission = $part['commission_total_minor'];

                    $suffix = $part['seller_type'] === 'platform' ? 'PLT' : 'V'.$part['vendor_id'];
                    $soNumber = $order->order_number.'-'.$suffix;

                    /** @var SellerOrder $sellerOrder */
                    $sellerOrder = SellerOrder::create([
                        'uuid' => (string) Str::uuid(),
                        'tenant_id' => $order->tenant_id,
                        'store_id' => $order->store_id,
                        'order_id' => $order->id,
                        'seller_order_number' => $soNumber,
                        'seller_type' => $part['seller_type'],
                        'vendor_id' => $part['vendor_id'],
                        'commercial_model' => (string) $order->commercial_model_snapshot,
                        'currency' => $order->currency,
                        'subtotal_minor' => $soSubtotal,
                        'discount_minor' => $soDiscount,
                        'tax_minor' => $soTax,
                        'shipping_original_minor' => $shipOrig,
                        'shipping_discount_minor' => $shipDisc,
                        'shipping_final_minor' => $shipFinal,
                        'total_minor' => $soTotal,
                        'commission_total_minor' => $soCommission,
                        'status' => 'open',
                    ]);

                    /** @var OrderItem $lineItem */
                    foreach ($part['items'] as $lineItem) {
                        SellerOrderItem::create([
                            'tenant_id' => $order->tenant_id,
                            'seller_order_id' => $sellerOrder->id,
                            'order_item_id' => $lineItem->id,
                            'quantity' => (string) $lineItem->quantity,
                            'subtotal_minor' => $lineItem->subtotal_minor,
                            'discount_minor' => $lineItem->discount_minor,
                            'tax_minor' => $lineItem->tax_minor,
                            'total_minor' => $lineItem->total_minor,
                            'commission_minor' => (int) ($lineItem->commission_amount_minor ?? 0),
                        ]);
                    }

                    $createdSellerOrders->push($sellerOrder->load('items'));

                    $sumSubtotal += $soSubtotal;
                    $sumDiscount += $soDiscount;
                    $sumTax += $soTax;
                    $sumShipFinal += $shipFinal;
                    $sumTotal += $soTotal;
                }

                // 9. Assert exact financial conservation
                if ($sumSubtotal !== $order->merchandise_subtotal_minor) {
                    throw new InconsistentHistoricalShippingSnapshotException(
                        "Conservation violation: Subtotal sum [{$sumSubtotal}] != Order merchandise_subtotal [{$order->merchandise_subtotal_minor}]."
                    );
                }
                if ($sumDiscount !== $order->discount_total_minor) {
                    throw new InconsistentHistoricalShippingSnapshotException(
                        "Conservation violation: Discount sum [{$sumDiscount}] != Order discount_total [{$order->discount_total_minor}]."
                    );
                }
                if ($sumTax !== $order->tax_total_minor) {
                    throw new InconsistentHistoricalShippingSnapshotException(
                        "Conservation violation: Tax sum [{$sumTax}] != Order tax_total [{$order->tax_total_minor}]."
                    );
                }
                if ($sumShipFinal !== $order->shipping_total_minor) {
                    throw new InconsistentHistoricalShippingSnapshotException(
                        "Conservation violation: Shipping final sum [{$sumShipFinal}] != Order shipping_total [{$order->shipping_total_minor}]."
                    );
                }
                if ($sumTotal !== $order->grand_total_minor) {
                    throw new InconsistentHistoricalShippingSnapshotException(
                        "Conservation violation: Grand total sum [{$sumTotal}] != Order grand_total [{$order->grand_total_minor}]."
                    );
                }

                return $createdSellerOrders;
            });
        } catch (QueryException $e) {
            // Concurrent race won by another process
            /** @var Collection<int, SellerOrder> $winner */
            $winner = SellerOrder::query()
                ->where('tenant_id', $order->tenant_id)
                ->where('order_id', $order->id)
                ->with('items')
                ->get();

            if ($winner->isNotEmpty()) {
                return $winner;
            }

            throw $e;
        }
    }
}
