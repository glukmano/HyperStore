<?php

declare(strict_types=1);

namespace Modules\Fulfillment\Services;

use Modules\Fulfillment\Contracts\PackingStrategyInterface;
use Modules\Shipping\ValueObjects\PackageCandidate;
use Modules\Shipping\ValueObjects\Weight;

class DefaultPackingService implements PackingStrategyInterface
{
    private const string MAX_PACKAGE_WEIGHT_KG = '30.0000';

    /**
     * Groups compatible items into packages, splitting when max weight is exceeded.
     */
    public function pack(array $items, ?int $inventorySourceId = null): array
    {
        if (empty($items)) {
            return [];
        }

        $packages = [];
        $currentItems = [];
        /** @var numeric-string $currentWeightKg */
        $currentWeightKg = '0.0000';

        foreach ($items as $item) {
            if (! $item->isShippable) {
                continue;
            }

            /** @var numeric-string $unitWeightKg */
            $unitWeightKg = $item->unitWeight->toKg();

            for ($i = 0; $i < $item->quantity; $i++) {
                /** @var numeric-string $projectedWeight */
                $projectedWeight = bcadd($currentWeightKg, $unitWeightKg, 4);

                if (! empty($currentItems) && bccomp($projectedWeight, self::MAX_PACKAGE_WEIGHT_KG, 4) > 0) {
                    // Seal current package
                    $packages[] = new PackageCandidate(
                        items: $this->consolidateItems($currentItems),
                        totalWeight: Weight::of($currentWeightKg, 'kg'),
                        inventorySourceId: $inventorySourceId
                    );
                    $currentItems = [];
                    $currentWeightKg = '0.0000';
                }

                $currentItems[] = [
                    'product_id' => $item->productId,
                    'variant_id' => $item->variantId,
                    'quantity' => 1,
                    'weight' => $item->unitWeight,
                    'shipping_class_id' => $item->shippingClassId,
                ];
                /** @var numeric-string $currentWeightKg */
                $currentWeightKg = bcadd($currentWeightKg, $unitWeightKg, 4);
            }
        }

        if (! empty($currentItems)) {
            $packages[] = new PackageCandidate(
                items: $this->consolidateItems($currentItems),
                totalWeight: Weight::of($currentWeightKg, 'kg'),
                inventorySourceId: $inventorySourceId
            );
        }

        return $packages;
    }

    /**
     * Consolidate single-unit items back into quantity counts per product/variant.
     *
     * @param  array<int, array{product_id: int, variant_id: ?int, quantity: int, weight: Weight, shipping_class_id: ?int}>  $unitItems
     * @return array<int, array{product_id: int, variant_id: ?int, quantity: int, weight: Weight, shipping_class_id: ?int}>
     */
    private function consolidateItems(array $unitItems): array
    {
        $consolidated = [];
        foreach ($unitItems as $unit) {
            $key = $unit['product_id'].'_'.($unit['variant_id'] ?? '0').'_'.($unit['shipping_class_id'] ?? '0');
            if (! isset($consolidated[$key])) {
                $consolidated[$key] = $unit;
            } else {
                $consolidated[$key]['quantity'] += $unit['quantity'];
            }
        }

        return array_values($consolidated);
    }
}
