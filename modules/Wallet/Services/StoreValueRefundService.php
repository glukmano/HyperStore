<?php

declare(strict_types=1);

namespace Modules\Wallet\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Order\Models\Order;
use Modules\Order\Models\OrderPaymentTenderAllocation;
use Modules\Order\Models\RefundTenderAllocation;
use Modules\Order\Services\TenderRefundDispatchService;
use RuntimeException;

/**
 * D.50: refunds preserve the ORIGINAL tender allocation — never blindly
 * refunding everything to Store Credit, and never re-reading tender
 * allocations in a different order on a retry (which could otherwise
 * produce a different split).
 *
 * Phase-22 Owner Delta §5: this class computes and persists the tender
 * allocation (that math was already tender-neutral) but no longer applies
 * it itself — per-tender application is delegated to the tender-neutral
 * TenderRefundDispatchService, so Store Value is never the owner of Cash
 * (or, for that matter, of the external-gateway slice either).
 */
final class StoreValueRefundService
{
    public function __construct(
        private readonly TenderRefundDispatchService $dispatchService
    ) {}

    /**
     * @return list<array{tender_type: string, amount_minor: int, transaction_uuid: ?string, transaction_status: ?string}>
     */
    public function refundOrder(Order $order, int $totalRefundAmountMinor, string $refundEventUuid): array
    {
        $existing = RefundTenderAllocation::where('order_id', $order->id)
            ->where('refund_event_uuid', $refundEventUuid)
            ->orderBy('id')
            ->get();

        $allocations = $existing->isNotEmpty()
            ? $existing
            : $this->computeAndPersistAllocations($order, $totalRefundAmountMinor, $refundEventUuid);

        return $this->apply($order, $allocations);
    }

    /**
     * @return Collection<int, RefundTenderAllocation>
     */
    private function computeAndPersistAllocations(Order $order, int $totalRefundAmountMinor, string $refundEventUuid): Collection
    {
        $tenders = OrderPaymentTenderAllocation::where('order_id', $order->id)->orderBy('id')->get();
        if ($tenders->isEmpty()) {
            throw new RuntimeException("No tender allocations recorded for Order [{$order->id}] — cannot determine refund destination.");
        }

        $totalTendered = (int) $tenders->sum('amount_minor');
        if ($totalRefundAmountMinor > $totalTendered) {
            throw new RuntimeException("Refund amount [{$totalRefundAmountMinor}] exceeds total tendered amount [{$totalTendered}] for Order [{$order->id}].");
        }

        // Deterministic integer-minor-unit, largest-remainder proportional
        // allocation — the exact algorithm already proven in
        // CheckoutPricingOrchestrator's cart-discount-to-line allocation,
        // reused verbatim per Owner Delta §19. Ties broken by
        // tender-allocation insertion order (the `orderBy('id')` above).
        $floorShares = [];
        $remainders = [];
        $sumFloor = 0;

        foreach ($tenders as $tender) {
            $weight = (int) $tender->amount_minor;
            $prod = bcmul((string) $totalRefundAmountMinor, (string) $weight, 0);
            $floor = (int) bcdiv($prod, (string) $totalTendered, 0);
            $rem = (int) bcmod($prod, (string) $totalTendered);

            $floorShares[$tender->id] = $floor;
            $remainders[$tender->id] = $rem;
            $sumFloor += $floor;
        }

        $undistributed = $totalRefundAmountMinor - $sumFloor;

        $orderedIds = $tenders->pluck('id')->all();
        usort($orderedIds, function (int $a, int $b) use ($remainders): int {
            $diff = $remainders[$b] <=> $remainders[$a];

            return $diff !== 0 ? $diff : $a <=> $b;
        });

        for ($k = 0; $k < $undistributed; $k++) {
            $floorShares[$orderedIds[$k]] += 1;
        }

        // Assertion mirroring CheckoutTotals' own reconciliation check —
        // the sum of allocations MUST equal the requested amount exactly.
        $sumAllocated = array_sum($floorShares);
        if ($sumAllocated !== $totalRefundAmountMinor) {
            throw new RuntimeException("Refund tender allocation rounding discrepancy: allocated [{$sumAllocated}], requested [{$totalRefundAmountMinor}].");
        }

        return DB::transaction(function () use ($order, $tenders, $floorShares, $refundEventUuid): Collection {
            foreach ($tenders as $tender) {
                $amount = $floorShares[$tender->id];
                if ($amount <= 0) {
                    continue;
                }

                RefundTenderAllocation::create([
                    'tenant_id' => $order->tenant_id,
                    'order_id' => $order->id,
                    'refund_event_uuid' => $refundEventUuid,
                    'tender_type' => $tender->tender_type,
                    'source_reference' => $tender->source_reference,
                    'amount_minor' => $amount,
                    'currency' => $tender->currency,
                ]);
            }

            return RefundTenderAllocation::where('order_id', $order->id)
                ->where('refund_event_uuid', $refundEventUuid)
                ->orderBy('id')
                ->get();
        });
    }

    /**
     * @param  Collection<int, RefundTenderAllocation>  $allocations
     * @return list<array{tender_type: string, amount_minor: int, transaction_uuid: ?string, transaction_status: ?string}>
     */
    private function apply(Order $order, Collection $allocations): array
    {
        $results = [];

        foreach ($allocations as $allocation) {
            $results[] = $this->dispatchService->dispatch($order, $allocation);
        }

        return $results;
    }
}
