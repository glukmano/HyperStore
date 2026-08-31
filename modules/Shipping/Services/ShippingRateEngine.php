<?php

declare(strict_types=1);

namespace Modules\Shipping\Services;

use Illuminate\Support\Collection;
use Modules\Pricing\Contracts\CurrencyConversionInterface;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Contracts\ShippingZoneMatcherInterface;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Models\ShippingRestriction;
use Modules\Shipping\Models\ShippingSourceMethodMapping;
use Modules\Shipping\Registries\ShippingMethodTypeRegistry;
use Modules\Shipping\ValueObjects\RateBreakdown;
use Modules\Shipping\ValueObjects\ShippingRateQuote;
use Modules\Shipping\ValueObjects\ShippingRateRequest;

class ShippingRateEngine implements ShippingRateEngineInterface
{
    public function __construct(
        private readonly ShippingZoneMatcherInterface $zoneMatcher,
        private readonly ShippingMethodTypeRegistry $methodTypeRegistry,
        private readonly ?CurrencyConversionInterface $currencyConverter = null
    ) {}

    /**
     * Executes pure read-only rate calculation with zero side effects.
     *
     * @return Collection<int, ShippingRateQuote>
     */
    public function calculateQuotes(ShippingRateRequest $request): Collection
    {
        $tenantId = $request->context->tenantId;
        $matchedZones = $this->zoneMatcher->match($request->destination, $request->context);

        if ($matchedZones->isEmpty()) {
            return collect();
        }

        $zoneIds = $matchedZones->pluck('id')->all();

        // 1. Fetch active methods associated with matched zones
        $methods = ShippingMethod::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereHas('methodZones', fn ($q) => $q->whereIn('shipping_zone_id', $zoneIds))
            ->with(['methodZones', 'rateRules'])
            ->orderBy('priority', 'desc')
            ->get();

        $quotes = [];

        foreach ($methods as $method) {
            /** @var ShippingMethod $method */
            // 2. Check restrictions
            if ($this->isRestricted($method, $request)) {
                continue;
            }

            // 3. Find highest specificity matched zone for this method
            $matchedZone = $matchedZones->first(function ($zone) use ($method) {
                return $method->methodZones->contains('shipping_zone_id', $zone->id);
            });

            if (! $matchedZone) {
                continue;
            }

            // 4. Calculate rate using registered calculator
            if (! $this->methodTypeRegistry->has($method->rate_calculator_type)) {
                continue;
            }

            $calculator = $this->methodTypeRegistry->getCalculator($method->rate_calculator_type);
            $breakdown = $calculator->calculate($method, $matchedZone, $request);

            if ($breakdown === null) {
                continue;
            }

            // 5. Currency conversion if method currency differs from request context
            if ($method->currency !== $request->context->currency && $this->currencyConverter !== null) {
                $convertedAmount = $this->currencyConverter->convert(
                    $breakdown->finalAmount,
                    $request->context->currency,
                    $tenantId
                );
                $breakdown = new RateBreakdown(
                    baseRate: $convertedAmount,
                    perItemAmount: MoneyValue::fromMinor(0, $request->context->currency),
                    perWeightAmount: MoneyValue::fromMinor(0, $request->context->currency),
                    handlingFee: MoneyValue::fromMinor(0, $request->context->currency),
                    carrierMarkup: MoneyValue::fromMinor(0, $request->context->currency),
                    promotionDiscount: $breakdown->promotionDiscount,
                    finalAmount: $convertedAmount
                );
            }

            // 6. Apply Promotion FreeShipping Benefit if provided
            $finalBreakdown = $this->applyPromotionBenefits($breakdown, $request->promotionBenefits, $request->context->currency);

            $quotes[] = new ShippingRateQuote(
                methodId: $method->id,
                methodCode: $method->code,
                title: $method->name,
                description: $method->description,
                amount: $finalBreakdown->finalAmount,
                breakdown: $finalBreakdown,
                carrierCode: $method->metadata['carrier_code'] ?? null,
                serviceCode: $method->metadata['service_code'] ?? null,
                estimatedDaysMin: (int) ($method->metadata['transit_days_min'] ?? 1),
                estimatedDaysMax: (int) ($method->metadata['transit_days_max'] ?? 3),
                metadata: $method->metadata ?? []
            );
        }

        // Sort quotes: Priority (from method metadata / priority), Amount ASC, Code ASC
        usort($quotes, function (ShippingRateQuote $a, ShippingRateQuote $b) {
            $amtA = $a->amount->getMinorAmount();
            $amtB = $b->amount->getMinorAmount();
            if ($amtA !== $amtB) {
                return $amtA <=> $amtB;
            }

            return strcmp($a->methodCode, $b->methodCode);
        });

        return collect($quotes);
    }

    private function isRestricted(ShippingMethod $method, ShippingRateRequest $request): bool
    {
        $tenantId = $request->context->tenantId;

        // Check source-method mapping if source is specified on lines
        foreach ($request->lines as $line) {
            $sourceId = $line['inventory_source_id'] ?? null;
            if ($sourceId !== null) {
                $mapping = ShippingSourceMethodMapping::query()
                    ->where('tenant_id', $tenantId)
                    ->where('inventory_source_id', $sourceId)
                    ->where('shipping_method_id', $method->id)
                    ->first();

                if ($mapping !== null && ! $mapping->is_allowed) {
                    return true;
                }
            }
        }

        // Check explicit ShippingRestrictions
        $hasRestriction = ShippingRestriction::query()
            ->where('tenant_id', $tenantId)
            ->where('shipping_method_id', $method->id)
            ->exists();

        return $hasRestriction;
    }

    /**
     * @param  array<int, mixed>  $benefits
     */
    private function applyPromotionBenefits(RateBreakdown $breakdown, array $benefits, string $currency): RateBreakdown
    {
        if (empty($benefits)) {
            return $breakdown;
        }

        $isFreeShipping = false;
        foreach ($benefits as $benefit) {
            if (is_object($benefit) && (property_exists($benefit, 'type') && $benefit->type === 'free_shipping' || str_contains(get_class($benefit), 'FreeShipping'))) {
                $isFreeShipping = true;
                break;
            }
            if (is_array($benefit) && ($benefit['type'] ?? '') === 'free_shipping') {
                $isFreeShipping = true;
                break;
            }
        }

        if (! $isFreeShipping) {
            return $breakdown;
        }

        $discount = $breakdown->finalAmount;
        $zero = MoneyValue::fromMinor(0, $currency);

        return new RateBreakdown(
            baseRate: $breakdown->baseRate,
            perItemAmount: $breakdown->perItemAmount,
            perWeightAmount: $breakdown->perWeightAmount,
            handlingFee: $breakdown->handlingFee,
            carrierMarkup: $breakdown->carrierMarkup,
            promotionDiscount: $discount,
            finalAmount: $zero,
            appliedPromotionBenefits: ['free_shipping_benefit_applied' => true]
        );
    }
}
