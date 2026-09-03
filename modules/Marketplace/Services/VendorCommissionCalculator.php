<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Modules\Marketplace\Contracts\VendorCommissionQuoteServiceInterface;
use Modules\Marketplace\DTOs\CommissionQuoteDTO;
use Modules\Marketplace\Exceptions\CommissionCalculationException;
use Modules\Marketplace\Models\Vendor;
use Modules\Marketplace\Models\VendorCommissionRule;

final class VendorCommissionCalculator implements VendorCommissionQuoteServiceInterface
{
    public function quoteCommission(
        int $tenantId,
        int $vendorId,
        ?int $categoryId,
        int $basisMinor,
        string $currency
    ): CommissionQuoteDTO {
        if ($basisMinor < 0) {
            throw CommissionCalculationException::negativeBasis($basisMinor);
        }

        // 4-tier precedence resolution:
        // 1. Vendor + Category rule
        $rule = null;
        $ruleSource = 'tenant_default';
        $ruleRef = null;

        if ($categoryId !== null) {
            $rule = VendorCommissionRule::where('tenant_id', $tenantId)
                ->where('vendor_id', $vendorId)
                ->where('category_id', $categoryId)
                ->where('currency', $currency)
                ->where('is_active', true)
                ->first();

            if ($rule !== null) {
                $ruleSource = 'vendor_category';
                $ruleRef = $rule->uuid;
            }
        }

        // 2. Vendor global override
        if ($rule === null) {
            $rule = VendorCommissionRule::where('tenant_id', $tenantId)
                ->where('vendor_id', $vendorId)
                ->whereNull('category_id')
                ->where('currency', $currency)
                ->where('is_active', true)
                ->first();

            if ($rule !== null) {
                $ruleSource = 'vendor_global';
                $ruleRef = $rule->uuid;
            }
        }

        // 3. Plan base rule
        if ($rule === null) {
            /** @var Vendor|null $vendor */
            $vendor = Vendor::with('plan')->where('tenant_id', $tenantId)->find($vendorId);
            if ($vendor !== null && $vendor->plan !== null) {
                $plan = $vendor->plan;
                if ($plan->commission_rate_bps > 0 || $plan->fixed_fee_minor > 0) {
                    if ($plan->fixed_fee_minor > 0 && strtoupper($plan->currency) !== strtoupper($currency)) {
                        throw CommissionCalculationException::currencyMismatch($currency, $plan->currency);
                    }

                    $rateBps = $plan->commission_rate_bps;
                    $fixedFee = $plan->fixed_fee_minor;
                    $ruleSource = 'plan_base';
                    $ruleRef = $plan->uuid;

                    return $this->computeCommissionQuote($basisMinor, $rateBps, $fixedFee, $currency, $ruleSource, $ruleRef);
                }
            }
        }

        // 4. Tenant default rule
        if ($rule === null) {
            $rule = VendorCommissionRule::where('tenant_id', $tenantId)
                ->whereNull('vendor_id')
                ->whereNull('category_id')
                ->where('currency', $currency)
                ->where('is_active', true)
                ->first();

            if ($rule !== null) {
                $ruleSource = 'tenant_default';
                $ruleRef = $rule->uuid;
            }
        }

        if ($rule === null) {
            // Default zero commission fallback if no tenant rule defined
            return new CommissionQuoteDTO(
                basisMinor: $basisMinor,
                rateBps: 0,
                fixedFeeMinor: 0,
                commissionAmountMinor: 0,
                vendorNetAmountMinor: $basisMinor,
                currency: $currency,
                ruleSource: 'default_zero',
                ruleReference: null,
            );
        }

        if ($rule->fixed_fee_minor > 0 && strtoupper($rule->currency) !== strtoupper($currency)) {
            throw CommissionCalculationException::currencyMismatch($currency, $rule->currency);
        }

        return $this->computeCommissionQuote(
            $basisMinor,
            $rule->rate_basis_points,
            $rule->fixed_fee_minor,
            $currency,
            $ruleSource,
            $ruleRef
        );
    }

    private function computeCommissionQuote(
        int $basisMinor,
        int $rateBps,
        int $fixedFeeMinor,
        string $currency,
        string $ruleSource,
        ?string $ruleRef
    ): CommissionQuoteDTO {
        if ($rateBps < 0 || $rateBps > 10000) {
            throw CommissionCalculationException::invalidRateBps($rateBps);
        }

        // Integer half-up rounding: floor((basis * bps + 5000) / 10000)
        $variableMinor = (int) floor((($basisMinor * $rateBps) + 5000) / 10000);
        $totalCommissionMinor = $variableMinor + $fixedFeeMinor;

        // Guard: 0 <= total <= basis
        if ($totalCommissionMinor > $basisMinor) {
            $totalCommissionMinor = $basisMinor;
        }

        $vendorNetAmountMinor = $basisMinor - $totalCommissionMinor;

        return new CommissionQuoteDTO(
            basisMinor: $basisMinor,
            rateBps: $rateBps,
            fixedFeeMinor: $fixedFeeMinor,
            commissionAmountMinor: $totalCommissionMinor,
            vendorNetAmountMinor: $vendorNetAmountMinor,
            currency: $currency,
            ruleSource: $ruleSource,
            ruleReference: $ruleRef,
        );
    }
}
