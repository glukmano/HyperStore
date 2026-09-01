<?php

declare(strict_types=1);

namespace Modules\Shipping\Services;

use Modules\Pricing\Contracts\CurrencyConversionInterface;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Calculators\CarrierCalculatedRateCalculator;
use Modules\Shipping\Contracts\ShippingPromotionBenefitInterface;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\Contracts\ShippingZoneMatcherInterface;
use Modules\Shipping\Models\ShippingMethod;
use Modules\Shipping\Registries\ShippingMethodTypeRegistry;
use Modules\Shipping\ValueObjects\RateBreakdown;
use Modules\Shipping\ValueObjects\ShippingRateOutcome;
use Modules\Shipping\ValueObjects\ShippingRateQuote;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\ShippingRateResult;

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
     */
    public function calculateQuotes(ShippingRateRequest $request): ShippingRateResult
    {
        CarrierCalculatedRateCalculator::clearErrors();
        $tenantId = $request->context->tenantId;

        // Digital / Non-physical items only -> No physical shipping required
        if (empty($request->lines)) {
            return new ShippingRateResult(
                quotes: collect(),
                outcome: ShippingRateOutcome::NO_SHIPPING_REQUIRED,
                errors: [],
                warnings: ['No physical items require shipping.'],
                matchedZones: collect()
            );
        }

        // 0. Pre-rating fulfillment readiness evaluation
        if ($request->hasUnfulfillableItems === true) {
            return new ShippingRateResult(
                quotes: collect(),
                outcome: ShippingRateOutcome::UNFULFILLABLE_ITEMS,
                errors: [],
                warnings: ['One or more items in the request are unfulfillable (no inventory stock / fulfillment source).'],
                matchedZones: collect()
            );
        }

        $matchedZones = $this->zoneMatcher->match($request->destination, $request->context);

        if ($matchedZones->isEmpty()) {
            return new ShippingRateResult(
                quotes: collect(),
                outcome: ShippingRateOutcome::NO_METHOD_AVAILABLE,
                errors: [],
                warnings: ['No shipping zone matched destination.'],
                matchedZones: collect()
            );
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

        if ($methods->isEmpty()) {
            return new ShippingRateResult(
                quotes: collect(),
                outcome: ShippingRateOutcome::NO_METHOD_AVAILABLE,
                errors: [],
                warnings: ['No active shipping methods associated with matched zones.'],
                matchedZones: $matchedZones
            );
        }

        $quotes = [];
        $hasRestrictedMethods = false;
        $carrierMethodsCount = 0;

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
                $hasRestrictedMethods = true;

                continue;
            }

            // 4. Calculate rate using registered calculator
            if (! $this->methodTypeRegistry->has($method->rate_calculator_type)) {
                continue;
            }

            if ($method->rate_calculator_type === 'carrier_calculated') {
                $carrierMethodsCount++;
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

            // 6. Apply Promotion FreeShipping Benefit via typed interface only
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

        $quotesCollection = collect($quotes);
        $errors = CarrierCalculatedRateCalculator::getErrors();

        // Determine outcome
        if ($quotesCollection->isNotEmpty()) {
            $outcome = ShippingRateOutcome::SUCCESS;
        } elseif (! empty($errors) && $carrierMethodsCount > 0) {
            $outcome = ShippingRateOutcome::PROVIDER_FAILURE;
        } elseif ($hasRestrictedMethods) {
            $outcome = ShippingRateOutcome::DESTINATION_RESTRICTED;
        } else {
            $outcome = ShippingRateOutcome::NO_METHOD_AVAILABLE;
        }

        return new ShippingRateResult(
            quotes: $quotesCollection,
            outcome: $outcome,
            errors: $errors,
            warnings: [],
            matchedZones: $matchedZones
        );
    }

    /**
     * @param  array<int, ShippingPromotionBenefitInterface>  $benefits
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
            if ($benefit->isFreeShipping()) {
                $applicableCode = $benefit->getApplicableMethodCode();
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
