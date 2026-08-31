<?php

declare(strict_types=1);

namespace Modules\Shipping\Services;

use Illuminate\Support\Collection;
use Modules\Pricing\Contracts\CurrencyConversionInterface;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\ShippingPromotionBenefitInterface;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Contracts\ShippingZoneMatcherInterface;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Registries\ShippingMethodTypeRegistry;
use Modules\Shipping\ValueObjects\RateBreakdown;
use Modules\Shipping\ValueObjects\ShippingRateQuote;
use Modules\Shipping\ValueObjects\ShippingRateRequest;

class ShippingRateEngine implements ShippingRateEngineInterface
{
    public function __construct(
        private readonly ShippingZoneMatcherInterface $zoneMatcher,
        private readonly ShippingMethodTypeRegistry $methodTypeRegistry,
        private readonly ShippingRestrictionEvaluator $restrictionEvaluator,
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
            // 2. Find highest specificity matched zone for this method
            $matchedZone = $matchedZones->first(function ($zone) use ($method) {
                return $method->methodZones->contains('shipping_zone_id', $zone->id);
            });

            if (! $matchedZone) {
                continue;
            }

            // 3. Check restrictions via typed evaluator
            $restrictionResult = $this->restrictionEvaluator->evaluate($method, $matchedZone, $request);
            if ($restrictionResult->isRestricted) {
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

            // 5. Currency conversion: convert EACH breakdown component independently
            $methodCurrency = $method->currency ?? $request->context->currency;
            if ($methodCurrency !== $request->context->currency && $this->currencyConverter !== null) {
                $targetCurr = $request->context->currency;
                $convBase = $this->currencyConverter->convert($breakdown->baseRate, $targetCurr, $tenantId);
                $convPerItem = $this->currencyConverter->convert($breakdown->perItemAmount, $targetCurr, $tenantId);
                $convPerWeight = $this->currencyConverter->convert($breakdown->perWeightAmount, $targetCurr, $tenantId);
                $convHandling = $this->currencyConverter->convert($breakdown->handlingFee, $targetCurr, $tenantId);
                $convMarkup = $this->currencyConverter->convert($breakdown->carrierMarkup, $targetCurr, $tenantId);
                $convDiscount = $this->currencyConverter->convert($breakdown->promotionDiscount, $targetCurr, $tenantId);
                $convFinal = $convBase->add($convPerItem)->add($convPerWeight)->add($convHandling)->add($convMarkup)->subtract($convDiscount);

                $breakdown = new RateBreakdown(
                    baseRate: $convBase,
                    perItemAmount: $convPerItem,
                    perWeightAmount: $convPerWeight,
                    handlingFee: $convHandling,
                    carrierMarkup: $convMarkup,
                    promotionDiscount: $convDiscount,
                    finalAmount: $convFinal
                );
            }

            // 6. Apply Promotion FreeShipping Benefit if provided (typed contract & array adapter)
            $finalBreakdown = $this->applyPromotionBenefits($breakdown, $method->code, $request->promotionBenefits, $request->context->currency);

            $quotes[] = new ShippingRateQuote(
                methodId: $method->id,
                methodCode: $method->code,
                title: $method->name,
                description: $method->description,
                amount: $finalBreakdown->finalAmount,
                breakdown: $finalBreakdown,
                methodPriority: (int) $method->priority,
                carrierCode: $method->metadata['carrier_code'] ?? null,
                serviceCode: $method->metadata['service_code'] ?? null,
                estimatedDaysMin: (int) ($method->metadata['transit_days_min'] ?? 1),
                estimatedDaysMax: (int) ($method->metadata['transit_days_max'] ?? 3),
                metadata: $method->metadata ?? []
            );
        }

        // Sort quotes: Priority DESC, Amount ASC, Code ASC
        usort($quotes, function (ShippingRateQuote $a, ShippingRateQuote $b) {
            if ($a->methodPriority !== $b->methodPriority) {
                return $b->methodPriority <=> $a->methodPriority; // Priority DESC
            }
            $amtA = $a->amount->getMinorAmount();
            $amtB = $b->amount->getMinorAmount();
            if ($amtA !== $amtB) {
                return $amtA <=> $amtB; // Amount ASC
            }

            return strcmp($a->methodCode, $b->methodCode); // Code ASC
        });

        return collect($quotes);
    }

    /**
     * @param  array<int, mixed>  $benefits
     */
    private function applyPromotionBenefits(
        RateBreakdown $breakdown,
        string $methodCode,
        array $benefits,
        string $currency
    ): RateBreakdown {
        if (empty($benefits)) {
            return $breakdown;
        }

        $isFreeShipping = false;
        foreach ($benefits as $benefit) {
            if ($benefit instanceof ShippingPromotionBenefitInterface && $benefit->isFreeShipping()) {
                $applicableCode = $benefit->getApplicableMethodCode();
                if ($applicableCode === null || $applicableCode === $methodCode) {
                    $isFreeShipping = true;
                    break;
                }
            } elseif (is_array($benefit) && ($benefit['type'] ?? '') === 'free_shipping') {
                $applicableCode = $benefit['applicable_method_code'] ?? null;
                if ($applicableCode === null || $applicableCode === $methodCode) {
                    $isFreeShipping = true;
                    break;
                }
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
