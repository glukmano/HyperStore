<?php

declare(strict_types=1);

namespace Modules\Shipping\Services;

use Illuminate\Support\Collection;
use Modules\Shipping\Contracts\ShippingZoneMatcherInterface;
use Modules\Shipping\Models\ShippingZone;
use Modules\Shipping\Models\ShippingZoneAssignment;
use Modules\Shipping\Models\ShippingZoneRule;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingDestination;

class ShippingZoneMatcher implements ShippingZoneMatcherInterface
{
    /**
     * Matches shipping zones by strict specificity:
     * 1. Check exclusions (exclusion beats inclusion).
     * 2. Score match specificity (Postal exact > Postal prefix/range > Region > Country > Global).
     * 3. Sort by matched specificity DESC, priority DESC, code ASC.
     *
     * @return Collection<int, ShippingZone>
     */
    public function match(ShippingDestination $destination, ShippingContext $context): Collection
    {
        $zones = ShippingZone::query()
            ->where('tenant_id', $context->tenantId)
            ->where('status', 'active')
            ->with(['rules', 'assignments'])
            ->get();

        $matchingZonesWithScores = [];

        foreach ($zones as $zone) {
            /** @var ShippingZone $zone */
            if (! $this->isAssignedToContext($zone, $context)) {
                continue;
            }

            $rules = $zone->rules;
            if ($rules->isEmpty()) {
                continue;
            }

            // 1. Check explicit exclusions
            $isExcluded = false;
            foreach ($rules as $rule) {
                /** @var ShippingZoneRule $rule */
                if ($rule->is_exclusion && $this->ruleMatchesDestination($rule, $destination)) {
                    $isExcluded = true;
                    break;
                }
            }

            if ($isExcluded) {
                continue;
            }

            // 2. Score inclusion rules
            $bestSpecificity = 0;
            foreach ($rules as $rule) {
                /** @var ShippingZoneRule $rule */
                if (! $rule->is_exclusion && $this->ruleMatchesDestination($rule, $destination)) {
                    $score = $this->getRuleSpecificityScore($rule);
                    if ($score > $bestSpecificity) {
                        $bestSpecificity = $score;
                    }
                }
            }

            if ($bestSpecificity > 0) {
                $matchingZonesWithScores[] = [
                    'zone' => $zone,
                    'specificity' => $bestSpecificity,
                    'priority' => (int) $zone->priority,
                    'code' => $zone->code,
                ];
            }
        }

        // Sort: Specificity DESC, Priority DESC, Code ASC
        usort($matchingZonesWithScores, function ($a, $b) {
            if ($a['specificity'] !== $b['specificity']) {
                return $b['specificity'] <=> $a['specificity'];
            }
            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] <=> $a['priority'];
            }

            return strcmp($a['code'], $b['code']);
        });

        return collect(array_column($matchingZonesWithScores, 'zone'));
    }

    private function isAssignedToContext(ShippingZone $zone, ShippingContext $context): bool
    {
        $assignments = $zone->assignments;
        if ($assignments->isEmpty()) {
            return true; // Universal assignment for tenant
        }

        foreach ($assignments as $assignment) {
            /** @var ShippingZoneAssignment $assignment */
            $matchStore = $assignment->store_id === null || $assignment->store_id === $context->storeId;
            $matchMarket = $assignment->market_id === null || $assignment->market_id === $context->marketId;
            $matchChannel = $assignment->channel_id === null || $assignment->channel_id === $context->channelId;

            if ($matchStore && $matchMarket && $matchChannel) {
                return true;
            }
        }

        return false;
    }

    private function ruleMatchesDestination(ShippingZoneRule $rule, ShippingDestination $dest): bool
    {
        $destPostal = $dest->getNormalizedPostalCode();

        return match ($rule->rule_type) {
            'postal_exact' => $destPostal !== null && strtoupper(trim((string) $rule->postal_code_pattern)) === $destPostal,
            'postal_prefix' => $destPostal !== null && str_starts_with($destPostal, strtoupper(trim((string) $rule->postal_code_pattern))),
            'postal_range' => $destPostal !== null && is_numeric($destPostal) && is_numeric($rule->postal_code_range_start) && is_numeric($rule->postal_code_range_end)
                && (int) $destPostal >= (int) $rule->postal_code_range_start
                && (int) $destPostal <= (int) $rule->postal_code_range_end,
            'region' => $rule->country_code === $dest->countryCode && ($rule->region_code === null || $rule->region_code === $dest->regionCode),
            'country' => $rule->country_code === $dest->countryCode,
            'broad_global' => true,
            default => false,
        };
    }

    private function getRuleSpecificityScore(ShippingZoneRule $rule): int
    {
        return match ($rule->rule_type) {
            'postal_exact' => 100,
            'postal_prefix', 'postal_range' => 80,
            'region' => 60,
            'country' => 40,
            'broad_global' => 10,
            default => 0,
        };
    }
}
