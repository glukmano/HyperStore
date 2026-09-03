<?php

declare(strict_types=1);

namespace Modules\Dropshipping\Services;

use Brick\Math\BigDecimal;
use Modules\Catalog\Models\ProductVariant;
use Modules\Dropshipping\Contracts\SupplierRoutingEngineInterface;
use Modules\Dropshipping\Models\SupplierOffer;
use Modules\Pricing\Contracts\CurrencyConversionInterface;
use Modules\Pricing\ValueObjects\MoneyValue;

class SupplierRoutingEngine implements SupplierRoutingEngineInterface
{
    public function __construct(
        private readonly CurrencyConversionInterface $currencyConverter
    ) {}

    public function routeVariant(
        int $tenantId,
        ?int $vendorId,
        ProductVariant $variant,
        string $quantity,
        string $targetCurrency,
        ?string $deliveryCountryCode = null
    ): array {
        $requiredQty = BigDecimal::of($quantity);

        // Fetch candidate offers for this product_variant
        $candidateOffers = SupplierOffer::query()
            ->with(['supplier', 'supplierLocation', 'supplierProductVariant'])
            ->whereHas('supplierProductVariant', function ($q) use ($variant): void {
                $q->where('product_variant_id', $variant->id);
            })
            ->where('is_available', true)
            ->whereHas('supplierLocation', function ($q): void {
                $q->where('is_active', true);
            })
            ->whereHas('supplier', function ($q) use ($tenantId, $vendorId): void {
                $q->where('status', 'active')
                    ->where(function ($sq) use ($tenantId, $vendorId): void {
                        // 1. Platform supplier enabled for this tenant
                        $sq->where(function ($pq) use ($tenantId): void {
                            $pq->where('scope_type', 'platform')
                                ->whereHas('tenantAccesses', function ($aq) use ($tenantId): void {
                                    $aq->where('tenant_id', $tenantId)
                                        ->where('is_enabled', true);
                                });
                        })
                        // 2. Tenant supplier
                            ->orWhere(function ($tq) use ($tenantId): void {
                                $tq->where('scope_type', 'tenant')
                                    ->where('tenant_id', $tenantId);
                            })
                        // 3. Private vendor supplier matching vendor
                            ->orWhere(function ($vq) use ($tenantId, $vendorId): void {
                                $vq->where('scope_type', 'private_vendor')
                                    ->where('tenant_id', $tenantId);
                                if ($vendorId !== null) {
                                    $vq->where('vendor_id', $vendorId);
                                }
                            });
                    });
            })
            ->get();

        $evaluated = [];

        foreach ($candidateOffers as $offer) {
            $stockDec = BigDecimal::of((string) $offer->stock_quantity);
            if ($stockDec->compareTo($requiredQty) < 0) {
                continue; // Insufficient stock
            }

            // Normalise cost to targetCurrency using auditable conversion
            $offerCostMinor = $offer->cost_minor;
            $offerCurrency = $offer->currency;
            $offerMoney = MoneyValue::fromMinor($offerCostMinor, $offerCurrency);
            $conversionResult = $this->currencyConverter->convertWithAudit(
                amount: $offerMoney,
                targetCurrency: $targetCurrency,
                tenantId: $tenantId
            );

            $normalizedCostMinor = $conversionResult->convertedAmount->getMinorAmount();

            $evaluated[] = [
                'offer' => $offer,
                'normalized_cost_minor' => $normalizedCostMinor,
                'lead_time_days' => $offer->lead_time_days,
                'audit' => [
                    'offer_id' => $offer->id,
                    'supplier_id' => $offer->supplier_id,
                    'supplier_code' => $offer->supplier->code,
                    'original_cost_minor' => $offerCostMinor,
                    'original_currency' => $offerCurrency,
                    'target_currency' => $targetCurrency,
                    'normalized_cost_minor' => $normalizedCostMinor,
                    'exchange_rate' => $conversionResult->exchangeRateApplied,
                    'rate_id' => $conversionResult->exchangeRateId,
                    'is_inverse' => $conversionResult->isInverseRate,
                    'lead_time_days' => $offer->lead_time_days,
                    'stock_on_hand' => (string) $offer->stock_quantity,
                    'location_code' => $offer->supplierLocation->code,
                ],
            ];
        }

        if (empty($evaluated)) {
            return [
                'selected_offer' => null,
                'normalized_cost_minor' => null,
                'audit_snapshot' => [
                    'status' => 'no_viable_offer',
                    'candidates_evaluated' => 0,
                    'timestamp' => now()->toIso8601String(),
                ],
                'candidate_count' => 0,
            ];
        }

        // Sort by lowest normalized cost, then lowest lead time
        usort($evaluated, function ($a, $b): int {
            if ($a['normalized_cost_minor'] !== $b['normalized_cost_minor']) {
                return $a['normalized_cost_minor'] <=> $b['normalized_cost_minor'];
            }

            return $a['lead_time_days'] <=> $b['lead_time_days'];
        });

        $winner = $evaluated[0];

        return [
            'selected_offer' => $winner['offer'],
            'normalized_cost_minor' => $winner['normalized_cost_minor'],
            'audit_snapshot' => [
                'status' => 'routed',
                'selected_offer_id' => $winner['offer']->id,
                'supplier_id' => $winner['offer']->supplier_id,
                'all_candidates' => array_map(fn ($e) => $e['audit'], $evaluated),
                'timestamp' => now()->toIso8601String(),
            ],
            'candidate_count' => count($evaluated),
        ];
    }
}
