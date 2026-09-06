<?php

declare(strict_types=1);

namespace Modules\Wallet\Contracts;

use Modules\Checkout\Models\CheckoutSession;
use Modules\Order\Models\Order;

/**
 * Non-invasive Checkout/Order integration seam (the Affiliate/Auction/
 * Booking hook pattern, reused verbatim) — guarded by `app()->bound()` so a
 * Tenant with Wallet disabled has zero Store Value code path at all.
 */
interface StoreValueCheckoutHookInterface
{
    /**
     * Places a hold for the given instrument type against the
     * CheckoutSession's customer, up to the requested amount (capped at
     * whatever is actually available — never more). Updates
     * checkout_sessions.store_value_applied_minor/store_value_hold_refs.
     * Idempotent per checkout session per instrument type.
     *
     * @return array{applied_minor: int}
     */
    public function applyToCheckout(CheckoutSession $session, string $instrumentType, ?string $accountUuid, int $requestedAmountMinor): array;

    /**
     * Removes a previously-applied hold (releases it) and clears the
     * session's store_value fields.
     */
    public function removeFromCheckout(CheckoutSession $session): void;

    /**
     * Releases every still-`held` Store Value entry for this Checkout
     * session (cancel/expire path) — never a capture.
     */
    public function releaseHoldsForCheckout(CheckoutSession $session): void;

    /**
     * Converts every still-`held` Store Value entry for the Order's
     * originating CheckoutSession into a final `capture`, and records the
     * order_payment_tender_allocations rows for both the Store Value
     * portion and the settlement portion (tagged by $settlementTenderType
     * — 'external_gateway' for a real gateway capture/zero-total
     * settlement, 'cash' for a Phase-22 POS cash settlement). Called from a
     * listener on Payment's PaymentCaptured event (never at Order-creation
     * time itself — the hold must not convert to capture before payment
     * truly succeeds).
     */
    public function convertHoldsToCaptureForOrder(Order $order, int $settledAmountMinor, string $settlementTransactionUuid, string $settlementTenderType = 'external_gateway'): void;
}
