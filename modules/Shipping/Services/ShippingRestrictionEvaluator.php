<?php

declare(strict_types=1);

namespace Modules\Shipping\Services;

use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingRestriction;
use Modules\Shipping\Models\ShippingSourceMethodMapping;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\ValueObjects\RestrictionResult;
use Modules\Shipping\ValueObjects\ShippingRateRequest;

class ShippingRestrictionEvaluator
{
    public function evaluate(ShippingMethod $method, ShippingZone $zone, ShippingRateRequest $request): RestrictionResult
    {
        $tenantId = $request->context->tenantId;

        // 1. Check Source-Method compatibility
        foreach ($request->lines as $line) {
            $sourceId = $line['inventory_source_id'] ?? null;
            if ($sourceId !== null) {
                $mapping = ShippingSourceMethodMapping::query()
                    ->where('tenant_id', $tenantId)
                    ->where('inventory_source_id', $sourceId)
                    ->where('shipping_method_id', $method->id)
                    ->first();

                if ($mapping !== null && ! $mapping->is_allowed) {
                    return RestrictionResult::restricted(
                        "Shipping method [{$method->code}] is not allowed for inventory source [{$sourceId}].",
                        'source_method_compatibility'
                    );
                }
            }
        }

        // 2. Check explicit typed ShippingRestrictions
        $restrictions = ShippingRestriction::query()
            ->where('tenant_id', $tenantId)
            ->where('shipping_method_id', $method->id)
            ->get();

        foreach ($restrictions as $restriction) {
            /** @var ShippingRestriction $restriction */
            if ($restriction->shipping_zone_id !== null && $restriction->shipping_zone_id === $zone->id) {
                return RestrictionResult::restricted("Method restricted for zone [{$zone->code}].", $restriction->restriction_type);
            }

            if ($restriction->target_type !== null) {
                $matched = match ($restriction->target_type) {
                    'shipping_class' => $this->matchesShippingClass($restriction->target_id, $request),
                    'product' => $this->matchesProduct($restriction->target_id, $request),
                    'inventory_source' => $this->matchesSource($restriction->target_id, $request),
                    default => false,
                };

                if ($matched) {
                    return RestrictionResult::restricted(
                        "Method restricted for target type [{$restriction->target_type}] with id [{$restriction->target_id}].",
                        $restriction->restriction_type
                    );
                }
            } elseif ($restriction->shipping_zone_id === null) {
                // Global method restriction
                return RestrictionResult::restricted('Method is restricted.', $restriction->restriction_type);
            }
        }

        return RestrictionResult::allowed();
    }

    private function matchesShippingClass(?int $targetClassId, ShippingRateRequest $request): bool
    {
        if ($targetClassId === null) {
            return false;
        }

        foreach ($request->lines as $line) {
            if (isset($line['shipping_class_id']) && (int) $line['shipping_class_id'] === $targetClassId) {
                return true;
            }
        }

        return false;
    }

    private function matchesProduct(?int $targetProductId, ShippingRateRequest $request): bool
    {
        if ($targetProductId === null) {
            return false;
        }

        foreach ($request->lines as $line) {
            if (isset($line['product_id']) && (int) $line['product_id'] === $targetProductId) {
                return true;
            }
        }

        return false;
    }

    private function matchesSource(?int $targetSourceId, ShippingRateRequest $request): bool
    {
        if ($targetSourceId === null) {
            return false;
        }

        foreach ($request->lines as $line) {
            if (isset($line['inventory_source_id']) && (int) $line['inventory_source_id'] === $targetSourceId) {
                return true;
            }
        }

        return false;
    }
}
