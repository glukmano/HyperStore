<?php

declare(strict_types=1);

namespace Modules\Checkout\DTOs;

use Carbon\Carbon;

final readonly class CheckoutReadyResult
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $customerData
     * @param  array<string, mixed>|null  $shippingAddress
     * @param  array<string, mixed>|null  $billingAddress
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>|null  $pricingSnapshot
     * @param  array<string, mixed>|null  $taxSnapshot
     * @param  array<string, mixed>|null  $promotionSnapshot
     * @param  array<string, mixed>|null  $fulfillmentSnapshot
     * @param  array<string, mixed>|null  $selectedShippingQuote
     * @param  array<int, int>  $reservationReferences
     */
    public function __construct(
        public int $checkoutSessionId,
        public string $checkoutUuid,
        public int $tenantId,
        public int $cartId,
        public int $cartVersion,
        public array $context,
        public array $customerData,
        public ?array $shippingAddress,
        public ?array $billingAddress,
        /** @var array<int, array<string, mixed>> */
        public array $lines,
        public array $totals,
        public ?array $pricingSnapshot,
        public ?array $taxSnapshot,
        public ?array $promotionSnapshot,
        public ?array $fulfillmentSnapshot,
        public ?array $selectedShippingQuote,
        public array $reservationReferences,
        public string $state,
        public Carbon $finalizedAt
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'checkout_session_id' => $this->checkoutSessionId,
            'checkout_uuid' => $this->checkoutUuid,
            'tenant_id' => $this->tenantId,
            'cart_id' => $this->cartId,
            'cart_version' => $this->cartVersion,
            'context' => $this->context,
            'customer_data' => $this->customerData,
            'shipping_address' => $this->shippingAddress,
            'billing_address' => $this->billingAddress,
            'lines' => $this->lines,
            'totals' => $this->totals,
            'pricing_snapshot' => $this->pricingSnapshot,
            'tax_snapshot' => $this->taxSnapshot,
            'promotion_snapshot' => $this->promotionSnapshot,
            'fulfillment_snapshot' => $this->fulfillmentSnapshot,
            'selected_shipping_quote' => $this->selectedShippingQuote,
            'reservation_references' => $this->reservationReferences,
            'state' => $this->state,
            'finalized_at' => $this->finalizedAt->toIso8601String(),
        ];
    }
}
