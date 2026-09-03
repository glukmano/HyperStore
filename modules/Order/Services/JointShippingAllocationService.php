<?php

declare(strict_types=1);

namespace Modules\Order\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Modules\Order\Exceptions\InconsistentHistoricalShippingSnapshotException;

class JointShippingAllocationService
{
    /**
     * @param array<string, array{
     *     seller_type: string,
     *     vendor_id: int|null,
     *     eligible_subtotal_minor: int,
     *     has_shipping_eligible_items: bool
     * }> $partitions Keyed by unique partition key (e.g. 'platform' or 'vendor:123')
     * @return array<string, array{
     *     shipping_final_minor: int,
     *     shipping_discount_minor: int,
     *     shipping_original_minor: int
     * }>
     */
    public function allocate(
        array $partitions,
        int $shippingFinalMinor,
        int $shippingDiscountMinor,
        int $shippingOriginalMinor
    ): array {
        if ($shippingOriginalMinor !== ($shippingFinalMinor + $shippingDiscountMinor)) {
            throw new InconsistentHistoricalShippingSnapshotException(
                "Original shipping [{$shippingOriginalMinor}] does not equal final [{$shippingFinalMinor}] + discount [{$shippingDiscountMinor}]."
            );
        }

        $result = [];
        $eligibleKeys = [];
        $totalWeight = 0;

        foreach ($partitions as $key => $partition) {
            $result[$key] = [
                'shipping_final_minor' => 0,
                'shipping_discount_minor' => 0,
                'shipping_original_minor' => 0,
            ];

            if ($partition['has_shipping_eligible_items']) {
                $eligibleKeys[] = $key;
                $totalWeight += $partition['eligible_subtotal_minor'];
            }
        }

        $eligibleCount = count($eligibleKeys);

        // Case 1: No shipping at all
        if ($shippingFinalMinor === 0 && $shippingDiscountMinor === 0) {
            return $result;
        }

        // Case 2: Shipping exists but no shipping-eligible partitions
        if ($eligibleCount === 0) {
            throw new InconsistentHistoricalShippingSnapshotException(
                "Order requires shipping [{$shippingFinalMinor}] but has zero shipping-eligible partitions."
            );
        }

        // Sort eligible keys deterministically for consistent tie-breaking
        sort($eligibleKeys);

        // Allocate Final and Discount separately using Largest Remainder, then derive Original
        $allocatedFinal = $this->distributeAmount($eligibleKeys, $partitions, $totalWeight, $shippingFinalMinor);
        $allocatedDiscount = $this->distributeAmount($eligibleKeys, $partitions, $totalWeight, $shippingDiscountMinor);

        $sumFinal = 0;
        $sumDiscount = 0;
        $sumOriginal = 0;

        foreach ($eligibleKeys as $key) {
            $f = $allocatedFinal[$key];
            $d = $allocatedDiscount[$key];
            $o = $f + $d;

            $result[$key]['shipping_final_minor'] = $f;
            $result[$key]['shipping_discount_minor'] = $d;
            $result[$key]['shipping_original_minor'] = $o;

            $sumFinal += $f;
            $sumDiscount += $d;
            $sumOriginal += $o;
        }

        if ($sumFinal !== $shippingFinalMinor) {
            throw new InconsistentHistoricalShippingSnapshotException(
                "Final shipping allocation sum [{$sumFinal}] does not match target [{$shippingFinalMinor}]."
            );
        }

        if ($sumDiscount !== $shippingDiscountMinor) {
            throw new InconsistentHistoricalShippingSnapshotException(
                "Discount shipping allocation sum [{$sumDiscount}] does not match target [{$shippingDiscountMinor}]."
            );
        }

        if ($sumOriginal !== $shippingOriginalMinor) {
            throw new InconsistentHistoricalShippingSnapshotException(
                "Original shipping allocation sum [{$sumOriginal}] does not match target [{$shippingOriginalMinor}]."
            );
        }

        return $result;
    }

    /**
     * @param  list<string>  $eligibleKeys
     * @param array<string, array{
     *     seller_type: string,
     *     vendor_id: int|null,
     *     eligible_subtotal_minor: int,
     *     has_shipping_eligible_items: bool
     * }> $partitions
     * @return array<string, int>
     */
    private function distributeAmount(
        array $eligibleKeys,
        array $partitions,
        int $totalWeight,
        int $amountToDistribute
    ): array {
        $count = count($eligibleKeys);
        if ($amountToDistribute === 0) {
            $zeroRes = [];
            foreach ($eligibleKeys as $k) {
                $zeroRes[$k] = 0;
            }

            return $zeroRes;
        }

        $allocations = [];
        $remainders = [];

        // Subcase A: Zero weight across all eligible partitions -> equal integer division
        if ($totalWeight === 0) {
            $base = intdiv($amountToDistribute, $count);
            $leftover = $amountToDistribute % $count;

            foreach ($eligibleKeys as $idx => $key) {
                $allocations[$key] = $base + ($idx < $leftover ? 1 : 0);
            }

            return $allocations;
        }

        // Subcase B: Proportional Largest Remainder Method
        $allocatedSum = 0;
        $totalWeightDec = BigDecimal::of($totalWeight);
        $amountDec = BigDecimal::of($amountToDistribute);

        foreach ($eligibleKeys as $key) {
            $w = BigDecimal::of($partitions[$key]['eligible_subtotal_minor']);
            // quota = (weight * amount) / total_weight
            $exactQuota = $w->multipliedBy($amountDec)->dividedBy($totalWeightDec, 10, RoundingMode::Down);
            $floorInt = (int) (string) $exactQuota->toBigInteger();
            $remainderDec = $exactQuota->minus(BigDecimal::of($floorInt));

            $allocations[$key] = $floorInt;
            $allocatedSum += $floorInt;
            $remainders[$key] = $remainderDec;
        }

        $leftover = $amountToDistribute - $allocatedSum;

        if ($leftover > 0) {
            // Sort keys by remainder descending, tie-break by key string ascending
            uksort($remainders, function (string $a, string $b) use ($remainders): int {
                $cmp = $remainders[$b]->compareTo($remainders[$a]);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcmp($a, $b);
            });

            $distributedLeftover = 0;
            foreach (array_keys($remainders) as $k) {
                if ($distributedLeftover >= $leftover) {
                    break;
                }
                $allocations[$k]++;
                $distributedLeftover++;
            }
        }

        return $allocations;
    }
}
