<?php

declare(strict_types=1);

namespace Modules\Checkout\Services;

use Modules\Cart\Models\Cart;
use Modules\Cart\Models\CartLine;
use Modules\Catalog\Contracts\ProductShippingCapabilityResolverInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\SelectedShippingQuote;
use Modules\Checkout\Exceptions\PriceUnavailableException;
use Modules\Checkout\Exceptions\ShippingQuoteExpiredException;
use Modules\Checkout\Exceptions\ShippingQuoteStaleException;
use Modules\Fulfillment\Contracts\FulfillmentPlanningServiceInterface;
use Modules\Fulfillment\DTOs\FulfillmentItemLine;
use Modules\Fulfillment\DTOs\FulfillmentPlan;
use Modules\Pricing\Contracts\PriceResolverInterface;
use Modules\Pricing\DTOs\PricingContext;
use Modules\Pricing\DTOs\PricingItem;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Promotions\Contracts\ShippingPromotionBenefitResolverInterface;
use Modules\Promotions\DTOs\PromotionCartItem;
use Modules\Promotions\DTOs\PromotionContext;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\ValueObjects\PackageCandidate;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingRateQuote;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use RuntimeException;

class CheckoutShippingOrchestrator
{
    public function __construct(
        private readonly ProductShippingCapabilityResolverInterface $shippingCapabilityResolver,
        private readonly FulfillmentPlanningServiceInterface $fulfillmentService,
        private readonly ShippingRateEngineInterface $shippingRateEngine,
        private readonly PriceResolverInterface $priceResolver,
        private readonly ShippingPromotionBenefitResolverInterface $promotionBenefitResolver,
    ) {}

    /**
     * Resolves shippability, fulfillment plan, and shipping rate quotes for a checkout session.
     *
     * @return array{
     *     fulfillment_plan: FulfillmentPlan,
     *     shipping_result: mixed
     * }
     */
    public function quote(Cart $cart, CheckoutAddress $destination): array
    {
        $cart->loadMissing('lines.product');

        $pricingCtx = new PricingContext(
            tenantId: $cart->tenant_id,
            currency: $cart->currency,
            storeId: $cart->store_id,
            marketId: $cart->market_id,
            channelId: $cart->channel_id,
            customerGroupId: null
        );

        $fLines = [];
        $physicalShippingLines = [];
        $promoItems = [];

        foreach ($cart->lines as $line) {
            /** @var CartLine $line */
            $product = $line->product;
            $qtyStr = (string) $line->quantity;
            /** @var numeric-string $unitWeightStr */
            $unitWeightStr = is_numeric($product->weight_kg ?? null) ? (string) $product->weight_kg : '0.0000';
            $unitWeight = Weight::of(bccomp($unitWeightStr, '0', 4) > 0 ? $unitWeightStr : '0.0001', 'kg');
            $isShippable = $this->shippingCapabilityResolver->requiresPhysicalShipping($product);
            $shippingClassId = isset($product->shipping_class_id) ? (int) $product->shipping_class_id : null;

            $pItem = new PricingItem(
                productId: $line->product_id,
                variantId: $line->variant_id,
                quantity: $qtyStr
            );
            $priceRes = $this->priceResolver->resolve($pItem, $pricingCtx);
            if ($priceRes === null) {
                throw PriceUnavailableException::forProduct($line->product_id, $line->variant_id);
            }

            $fLines[] = new FulfillmentItemLine(
                productId: $line->product_id,
                variantId: $line->variant_id,
                quantity: $qtyStr,
                unitPrice: $priceRes->unitPrice,
                unitWeight: $unitWeight,
                dimensions: null,
                shippingClassId: $shippingClassId,
                isShippable: $isShippable
            );

            if ($isShippable) {
                $physicalShippingLines[] = [
                    'product_id' => $line->product_id,
                    'variant_id' => $line->variant_id,
                    'quantity' => $qtyStr,
                    'unit_price' => $priceRes->unitPrice,
                    'unit_weight' => $unitWeight,
                    'shipping_class_id' => $shippingClassId,
                    'is_shippable' => true,
                ];
            }

            $promoItems[] = new PromotionCartItem(
                productId: $line->product_id,
                variantId: $line->variant_id,
                quantity: $qtyStr,
                unitPrice: $priceRes->unitPrice,
                categoryIds: [],
                productType: $product->product_type
            );
        }

        $shippingCtx = new ShippingContext(
            tenantId: $cart->tenant_id,
            currency: $cart->currency,
            storeId: $cart->store_id,
            marketId: $cart->market_id,
            channelId: $cart->channel_id
        );

        $plan = $this->fulfillmentService->plan($cart->tenant_id, $fLines, $shippingCtx);

        $destVO = $destination->toShippingDestination();

        $promoCtx = new PromotionContext(
            tenantId: $cart->tenant_id,
            currency: $cart->currency,
            items: $promoItems,
            storeId: $cart->store_id,
            marketId: $cart->market_id,
            channelId: $cart->channel_id,
            customerId: $cart->user_id,
            couponCodes: $cart->coupon_code !== null ? [$cart->coupon_code] : []
        );

        $promotionBenefits = $this->promotionBenefitResolver->resolveBenefits($promoCtx);

        $shippingReq = new ShippingRateRequest(
            context: $shippingCtx,
            destination: $destVO,
            lines: $physicalShippingLines,
            promotionBenefits: $promotionBenefits
        );

        $shippingResult = $this->shippingRateEngine->calculateQuotes($shippingReq);

        return [
            'fulfillment_plan' => $plan,
            'shipping_result' => $shippingResult,
        ];
    }

    /**
     * Re-quotes and derives an authoritative server-calculated SelectedShippingQuote with full canonical fingerprint.
     *
     * @param  array<string, mixed>  $clientSelection
     */
    public function buildAuthoritativeSelectedQuote(
        Cart $cart,
        CheckoutAddress $destination,
        array $clientSelection
    ): SelectedShippingQuote {
        $quoteRes = $this->quote($cart, $destination);
        $plan = $quoteRes['fulfillment_plan'];
        $shippingResult = $quoteRes['shipping_result'];

        $methodId = (int) $clientSelection['method_id'];
        $carrierCode = isset($clientSelection['carrier_code']) ? (string) $clientSelection['carrier_code'] : null;
        $serviceCode = isset($clientSelection['service_code']) ? (string) $clientSelection['service_code'] : null;

        /** @var ShippingRateQuote|null $matchedQuote */
        $matchedQuote = null;
        foreach ($shippingResult->quotes as $q) {
            if ((int) $q->methodId === $methodId) {
                if ($carrierCode !== null && $q->carrierCode !== $carrierCode) {
                    continue;
                }
                if ($serviceCode !== null && $q->serviceCode !== $serviceCode) {
                    continue;
                }
                $matchedQuote = $q;
                break;
            }
        }

        if ($matchedQuote === null) {
            throw new RuntimeException("Selected shipping method [{$methodId}] is not eligible or available for this checkout.");
        }

        $currency = $cart->currency;
        $originalAmountMinor = $matchedQuote->breakdown->baseRate->getMinorAmount()
            + $matchedQuote->breakdown->perItemAmount->getMinorAmount()
            + $matchedQuote->breakdown->perWeightAmount->getMinorAmount()
            + $matchedQuote->breakdown->handlingFee->getMinorAmount()
            + $matchedQuote->breakdown->carrierMarkup->getMinorAmount();
        $finalAmountMinor = $matchedQuote->amount->getMinorAmount();

        $breakdownArray = [
            'base_rate' => $matchedQuote->breakdown->baseRate->getMinorAmount(),
            'per_item' => $matchedQuote->breakdown->perItemAmount->getMinorAmount(),
            'per_weight' => $matchedQuote->breakdown->perWeightAmount->getMinorAmount(),
            'handling_fee' => $matchedQuote->breakdown->handlingFee->getMinorAmount(),
            'carrier_markup' => $matchedQuote->breakdown->carrierMarkup->getMinorAmount(),
            'promotion_discount' => $matchedQuote->breakdown->promotionDiscount->getMinorAmount(),
            'final_amount' => $matchedQuote->breakdown->finalAmount->getMinorAmount(),
        ];

        // 1. Fulfillment Allocations Canonical List
        $fulfillmentAllocations = [];
        foreach ($plan->groups as $g) {
            foreach ($g->items as $it) {
                /** @var FulfillmentItemLine $it */
                $fulfillmentAllocations[] = [
                    'source_id' => $g->inventorySourceId,
                    'product_id' => $it->productId,
                    'variant_id' => $it->variantId,
                    'quantity' => (string) $it->quantity,
                    'readiness' => is_object($g->readiness) && isset($g->readiness->value) ? (string) $g->readiness->value : (string) $g->readiness,
                ];
            }
        }
        /** @var list<array{source_id: int|null, product_id: int, variant_id: int|null, quantity: string, readiness: string}> $fulfillmentAllocations */
        usort($fulfillmentAllocations, fn (array $a, array $b): int => (($a['source_id'] ?? 0) <=> ($b['source_id'] ?? 0)) ?: ($a['product_id'] <=> $b['product_id']));

        // 2. Physical Lines Canonical List (Shippable physical lines only)
        $linesData = [];
        foreach ($cart->lines as $l) {
            /** @var CartLine $l */
            if (! $this->shippingCapabilityResolver->requiresPhysicalShipping($l->product)) {
                continue;
            }

            $linesData[] = [
                'product_id' => $l->product_id,
                'variant_id' => $l->variant_id,
                'quantity' => (string) $l->quantity,
                'unit_weight_kg' => (string) ($l->product->weight_kg ?? '0.0000'),
                'shipping_class_id' => isset($l->product->shipping_class_id) ? (int) $l->product->shipping_class_id : null,
            ];
        }
        /** @var list<array{product_id: int, variant_id: int|null, quantity: string, unit_weight_kg: string, shipping_class_id: int|null}> $linesData */
        usort($linesData, fn (array $a, array $b): int => ($a['product_id'] <=> $b['product_id']) ?: (($a['variant_id'] ?? 0) <=> ($b['variant_id'] ?? 0)));

        // 3. Packages Canonical List with Complete Item Composition
        $packagesData = [];
        foreach ($plan->groups as $g) {
            foreach ($g->packages as $pkgIndex => $pkg) {
                /** @var PackageCandidate $pkg */
                $pkgItems = [];
                foreach ($pkg->items as $pi) {
                    $pkgItems[] = [
                        'product_id' => (int) $pi['product_id'],
                        'variant_id' => $pi['variant_id'],
                        'quantity' => (string) $pi['quantity'],
                        'unit_weight_kg' => $pi['weight']->toKg(),
                        'shipping_class_id' => $pi['shipping_class_id'],
                    ];
                }
                usort($pkgItems, fn (array $a, array $b): int => ($a['product_id'] <=> $b['product_id']) ?: (($a['variant_id'] ?? 0) <=> ($b['variant_id'] ?? 0)));

                $pkgHash = hash('sha256', (string) json_encode($pkgItems));

                $packagesData[] = [
                    'package_index' => $pkgIndex,
                    'source_id' => $pkg->inventorySourceId,
                    'package_type_id' => $pkg->packageTypeId,
                    'weight_kg' => $pkg->totalWeight->toKg(),
                    'dimensions' => $pkg->dimensions !== null ? [
                        'length' => $pkg->dimensions->getLengthCm(),
                        'width' => $pkg->dimensions->getWidthCm(),
                        'height' => $pkg->dimensions->getHeightCm(),
                    ] : null,
                    'items' => $pkgItems,
                    'package_fingerprint' => $pkgHash,
                ];
            }
        }

        // 4. Authoritative Promotion Shipping Benefits via typed contract
        $promoItems = [];
        $pricingCtx = new PricingContext(
            tenantId: $cart->tenant_id,
            currency: $cart->currency,
            storeId: $cart->store_id,
            marketId: $cart->market_id,
            channelId: $cart->channel_id
        );
        foreach ($cart->lines as $line) {
            $pRes = $this->priceResolver->resolve(new PricingItem($line->product_id, $line->variant_id, (string) $line->quantity), $pricingCtx);
            if ($pRes !== null) {
                $promoItems[] = new PromotionCartItem(
                    productId: $line->product_id,
                    variantId: $line->variant_id,
                    quantity: (string) $line->quantity,
                    unitPrice: $pRes->unitPrice,
                    categoryIds: [],
                    productType: $line->product->product_type
                );
            }
        }

        $promoCtx = new PromotionContext(
            tenantId: $cart->tenant_id,
            currency: $cart->currency,
            items: $promoItems,
            storeId: $cart->store_id,
            marketId: $cart->market_id,
            channelId: $cart->channel_id,
            customerId: $cart->user_id,
            couponCodes: $cart->coupon_code !== null ? [$cart->coupon_code] : []
        );

        $resolvedBenefits = $this->promotionBenefitResolver->resolveBenefits($promoCtx);
        $canonicalBenefits = [];
        foreach ($resolvedBenefits as $b) {
            $canonicalBenefits[] = [
                'type' => $b->isFreeShipping() ? 'free_shipping' : 'shipping_discount',
                'applicable_method_code' => $b->getApplicableMethodCode(),
            ];
        }

        $benefitSnapshot = [
            'coupon_code' => $cart->coupon_code,
            'benefits' => $canonicalBenefits,
            'shipping_discount_minor' => $matchedQuote->breakdown->promotionDiscount->getMinorAmount(),
            'benefit_fingerprint' => hash('sha256', (string) json_encode($canonicalBenefits)),
        ];

        $rateRelevantInputs = [
            'tenant_id' => $cart->tenant_id,
            'store_id' => $cart->store_id,
            'market_id' => $cart->market_id,
            'channel_id' => $cart->channel_id,
            'currency' => $cart->currency,
            'destination' => $destination->toArray(),
            'method_id' => $matchedQuote->methodId,
            'method_code' => $matchedQuote->methodCode,
            'carrier_code' => $matchedQuote->carrierCode,
            'service_code' => $matchedQuote->serviceCode,
            'physical_lines' => $linesData,
            'fulfillment_allocations' => $fulfillmentAllocations,
            'packages' => $packagesData,
            'promotion_shipping_benefits' => $benefitSnapshot,
            'original_amount' => $originalAmountMinor,
            'final_amount' => $finalAmountMinor,
            'breakdown' => $breakdownArray,
        ];

        $fingerprint = SelectedShippingQuote::computeFingerprint($rateRelevantInputs);

        return new SelectedShippingQuote(
            methodId: $matchedQuote->methodId,
            methodCode: $matchedQuote->methodCode,
            carrierCode: $matchedQuote->carrierCode,
            serviceCode: $matchedQuote->serviceCode,
            originalAmount: MoneyValue::fromMinor($originalAmountMinor, $currency),
            finalAmount: MoneyValue::fromMinor($finalAmountMinor, $currency),
            fingerprint: $fingerprint,
            quotedAt: now(),
            expiresAt: now()->addMinutes(30),
            breakdown: $breakdownArray,
            rateRelevantInputs: $rateRelevantInputs
        );
    }

    /**
     * Fully revalidates the stored shipping quote against current checkout state, destination, fulfillment plan, and promotions.
     */
    public function revalidateSelectedQuote(Cart $cart, CheckoutAddress $destination, SelectedShippingQuote $selectedQuote): void
    {
        if ($selectedQuote->isExpired()) {
            throw new ShippingQuoteExpiredException("SHIPPING_QUOTE_EXPIRED: Selected shipping quote [{$selectedQuote->methodId}] has expired at [{$selectedQuote->expiresAt->toIso8601String()}].");
        }

        $freshQuote = $this->buildAuthoritativeSelectedQuote($cart, $destination, [
            'method_id' => $selectedQuote->methodId,
            'method_code' => $selectedQuote->methodCode,
            'carrier_code' => $selectedQuote->carrierCode,
            'service_code' => $selectedQuote->serviceCode,
        ]);

        if ($freshQuote->fingerprint !== $selectedQuote->fingerprint) {
            throw new ShippingQuoteStaleException("SHIPPING_QUOTE_STALE: Selected shipping quote [{$selectedQuote->methodId}] is no longer valid due to checkout state changes. Re-selection is required.");
        }
    }
}
