<?php

declare(strict_types=1);

namespace Modules\Checkout\Services;

use Modules\Cart\Models\Cart;
use Modules\Cart\Models\CartLine;
use Modules\Catalog\Contracts\ProductShippingCapabilityResolverInterface;
use Modules\Checkout\DTOs\CheckoutAddress;
use Modules\Checkout\DTOs\SelectedShippingQuote;
use Modules\Fulfillment\Contracts\FulfillmentPlanningServiceInterface;
use Modules\Fulfillment\DTOs\FulfillmentItemLine;
use Modules\Fulfillment\DTOs\FulfillmentPlan;
use Modules\Pricing\ValueObjects\MoneyValue;
use Modules\Shipping\Contracts\ShippingRateEngineInterface;
use Modules\Shipping\ValueObjects\ShippingContext;
use Modules\Shipping\ValueObjects\ShippingRateRequest;
use Modules\Shipping\ValueObjects\Weight;

class CheckoutShippingOrchestrator
{
    public function __construct(
        private readonly FulfillmentPlanningServiceInterface $fulfillmentService,
        private readonly ShippingRateEngineInterface $shippingRateEngine,
        private readonly ProductShippingCapabilityResolverInterface $shippingCapabilityResolver
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

        // 1. Build fulfillment lines
        $fLines = [];
        $physicalShippingLines = [];

        foreach ($cart->lines as $line) {
            /** @var CartLine $line */
            $product = $line->product;
            $qty = $line->getQuantityVO()->toInt();
            $unitWeightKg = (float) ($product->weight_kg ?? 0.0);
            $isShippable = $this->shippingCapabilityResolver->requiresPhysicalShipping($product);

            $fLines[] = new FulfillmentItemLine(
                productId: $line->product_id,
                variantId: $line->variant_id,
                quantity: $qty,
                unitPrice: MoneyValue::fromMinor(1000, $cart->currency),
                unitWeight: Weight::of((string) max(0.0001, $unitWeightKg), 'kg'),
                isShippable: $isShippable
            );

            // Filter strictly physical lines for ShippingRateRequest
            if ($isShippable) {
                $physicalShippingLines[] = [
                    'product_id' => $line->product_id,
                    'variant_id' => $line->variant_id,
                    'quantity' => $qty,
                    'unit_price' => MoneyValue::fromMinor(1000, $cart->currency),
                    'unit_weight' => Weight::of((string) max(0.0001, $unitWeightKg), 'kg'),
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
     * Builds SelectedShippingQuote with deterministic fingerprint.
     *
     * @param  array<string, mixed>  $rateQuote
     */
    public function buildSelectedQuote(
        Cart $cart,
        CheckoutAddress $destination,
        array $rateQuote,
        FulfillmentPlan $plan
    ): SelectedShippingQuote {
        $currency = $cart->currency;

        $contextData = [
            'tenant_id' => $cart->tenant_id,
            'store_id' => $cart->store_id,
            'market_id' => $cart->market_id,
            'channel_id' => $cart->channel_id,
            'currency' => $cart->currency,
        ];

        $fingerprint = hash('sha256', (string) json_encode([
            'context' => $contextData,
            'destination' => $destination->toArray(),
            'method_id' => (int) $rateQuote['method_id'],
            'method_code' => (string) $rateQuote['method_code'],
            'carrier_code' => $rateQuote['carrier_code'] ?? null,
            'service_code' => $rateQuote['service_code'] ?? null,
            'original_amount' => (int) $rateQuote['original_amount'],
            'final_amount' => (int) $rateQuote['final_amount'],
        ]));

        return new SelectedShippingQuote(
            methodId: (int) $rateQuote['method_id'],
            methodCode: (string) $rateQuote['method_code'],
            carrierCode: isset($rateQuote['carrier_code']) ? (string) $rateQuote['carrier_code'] : null,
            serviceCode: isset($rateQuote['service_code']) ? (string) $rateQuote['service_code'] : null,
            originalAmount: MoneyValue::fromMinor((int) $rateQuote['original_amount'], $currency),
            finalAmount: MoneyValue::fromMinor((int) $rateQuote['final_amount'], $currency),
            fingerprint: $fingerprint,
            breakdown: (array) ($rateQuote['breakdown'] ?? [])
        );
    }
}
