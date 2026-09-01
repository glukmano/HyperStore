<?php

declare(strict_types=1);

namespace Modules\Checkout\Services;

use Modules\Cart\Models\Cart;
use Modules\Cart\Models\CartLine;
use Modules\Catalog\Contracts\ProductShippingCapabilityResolverInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\SelectedShippingQuote;
use Modules\Checkout\Exceptions\PriceUnavailableException;
use Modules\Fulfillment\Contracts\FulfillmentPlanningServiceInterface;
use Modules\Fulfillment\DTOs\FulfillmentGroup;
use Modules\Fulfillment\DTOs\FulfillmentItemLine;
use Modules\Fulfillment\DTOs\FulfillmentPlan;
use Modules\Pricing\Contracts\PriceResolverInterface;
use Modules\Pricing\DTOs\PricingContext;
use Modules\Pricing\DTOs\PricingItem;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingRateQuote;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;
use RuntimeException;

class CheckoutShippingOrchestrator
{
    public function __construct(
        private readonly FulfillmentPlanningServiceInterface $fulfillmentService,
        private readonly ShippingRateEngineInterface $shippingRateEngine,
        private readonly ProductShippingCapabilityResolverInterface $shippingCapabilityResolver,
        private readonly PriceResolverInterface $priceResolver
    ) {}

    /**
     * Resolves fulfillment plan and queries fresh shipping rates.
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

        // 1. Build fulfillment lines with authoritative prices and exact weights
        $fLines = [];
        $physicalShippingLines = [];

        foreach ($cart->lines as $line) {
            /** @var CartLine $line */
            $product = $line->product;
            $qtyInt = max(1, (int) ceil((float) (string) $line->quantity));
            /** @var numeric-string $unitWeightStr */
            $unitWeightStr = is_numeric($product->weight_kg ?? null) ? (string) $product->weight_kg : '0.0000';
            $unitWeight = Weight::of(bccomp($unitWeightStr, '0', 4) > 0 ? $unitWeightStr : '0.0001', 'kg');
            $isShippable = $this->shippingCapabilityResolver->requiresPhysicalShipping($product);

            $pItem = new PricingItem(
                productId: $line->product_id,
                variantId: $line->variant_id,
                quantity: $qtyInt
            );
            $priceRes = $this->priceResolver->resolve($pItem, $pricingCtx);
            if ($priceRes === null) {
                throw PriceUnavailableException::forProduct($line->product_id, $line->variant_id);
            }

            $fLines[] = new FulfillmentItemLine(
                productId: $line->product_id,
                variantId: $line->variant_id,
                quantity: $qtyInt,
                unitPrice: $priceRes->unitPrice,
                unitWeight: $unitWeight,
                isShippable: $isShippable
            );

            if ($isShippable) {
                $physicalShippingLines[] = [
                    'product_id' => $line->product_id,
                    'variant_id' => $line->variant_id,
                    'quantity' => $qtyInt,
                    'unit_price' => $priceRes->unitPrice,
                    'unit_weight' => $unitWeight,
                    'is_shippable' => true,
                ];
            }
        }

        $shippingCtx = new ShippingContext(
            tenantId: $cart->tenant_id,
            currency: $cart->currency,
            storeId: $cart->store_id,
            marketId: $cart->market_id,
            channelId: $cart->channel_id
        );

        $plan = $this->fulfillmentService->plan($cart->tenant_id, $fLines, $shippingCtx);

        // 2. Query shipping rates if physical items exist
        $destVO = $destination->toShippingDestination();

        $shippingReq = new ShippingRateRequest(
            context: $shippingCtx,
            destination: $destVO,
            lines: $physicalShippingLines
        );

        $shippingResult = $this->shippingRateEngine->calculateQuotes($shippingReq);

        return [
            'fulfillment_plan' => $plan,
            'shipping_result' => $shippingResult,
        ];
    }

    /**
     * Re-quotes and derives an authoritative server-calculated SelectedShippingQuote with full fingerprint.
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

        // Gather complete rate-relevant inputs for comprehensive fingerprint
        $fulfillmentAllocations = [];
        foreach ($plan->groups as $g) {
            /** @var FulfillmentGroup $g */
            $fulfillmentAllocations[] = [
                'inventory_source_id' => $g->inventorySourceId,
                'is_shippable' => $g->isShippable,
                'items_count' => count($g->items),
            ];
        }

        $linesData = [];
        foreach ($cart->lines as $l) {
            /** @var CartLine $l */
            $linesData[] = [
                'product_id' => $l->product_id,
                'variant_id' => $l->variant_id,
                'quantity' => (string) $l->quantity,
            ];
        }

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
            'fulfillment_allocations' => $fulfillmentAllocations,
            'lines' => $linesData,
            'coupon_code' => $cart->coupon_code,
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
}
