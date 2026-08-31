<?php

declare(strict_types=1);

namespace Modules\Shipping\Calculators;

use Modules\Inventory\Contracts\InventorySourceQueryInterface;
use Modules\Inventory\DTOs\InventoryContext;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\RateCalculatorInterface;
use Modules\Shipping\Models\PickupLocation;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\ValueObjects\RateBreakdown;
use Modules\Shipping\ValueObjects\ShippingRateRequest;

class LocalPickupCalculator implements RateCalculatorInterface
{
    public function __construct(
        private readonly ?InventorySourceQueryInterface $sourceQuery = null
    ) {}

    public function calculate(ShippingMethod $method, ShippingZone $zone, ShippingRateRequest $request): ?RateBreakdown
    {
        $tenantId = $method->tenant_id;
        $currency = $method->currency ?? $request->context->currency;

        // 1. Verify active PickupLocation mapped to method
        $pickupLocationId = $method->metadata['pickup_location_id'] ?? null;
        $location = null;
        if ($pickupLocationId !== null) {
            $location = PickupLocation::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $pickupLocationId)
                ->where('status', 'active')
                ->first();
        } else {
            $location = PickupLocation::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->first();
        }

        if ($location === null) {
            return null; // No active pickup location
        }

        // 2. If mapped to inventory source and source query available, check availability
        if ($this->sourceQuery !== null && $location->inventory_source_id !== null) {
            $invContext = new InventoryContext(
                tenantId: $tenantId,
                storeId: $request->context->storeId,
                marketId: $request->context->marketId,
                channelId: $request->context->channelId
            );

            foreach ($request->lines as $line) {
                if ($line['is_shippable'] ?? true) {
                    $prodId = (int) $line['product_id'];
                    $variantId = isset($line['variant_id']) ? (int) $line['variant_id'] : null;
                    $qty = (int) $line['quantity'];

                    $avail = $this->sourceQuery->checkSourceAvailability(
                        $prodId,
                        $variantId,
                        (int) $location->inventory_source_id,
                        $invContext
                    );

                    if (! $avail->canFulfillQuantity($qty)) {
                        return null; // Insufficient stock for local pickup
                    }
                }
            }
        }

        $baseRate = MoneyValue::fromMinor((int) $method->base_amount, $currency);
        $handling = MoneyValue::fromMinor((int) $method->handling_fee, $currency);
        $zero = MoneyValue::fromMinor(0, $currency);
        $finalAmount = $baseRate->add($handling);

        return new RateBreakdown(
            baseRate: $baseRate,
            perItemAmount: $zero,
            perWeightAmount: $zero,
            handlingFee: $handling,
            carrierMarkup: $zero,
            promotionDiscount: $zero,
            finalAmount: $finalAmount
        );
    }
}
