# ADR-0143: Affiliate Attribution Freeze Boundary and Customer Referral Separation

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0143                              |
| Status      | Accepted                              |
| Date        | 2026-09-05                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-19                              |

## Context

Two related but distinct decisions were forced by the Owner Delta: (1) *when* Affiliate attribution and its commission calculation get decided and frozen relative to Checkout completion and payment confirmation, since Campaign configuration, Referral Code state, and Commission Rules can all change between order placement and payment; and (2) whether a Customer referring a friend and an Affiliate earning a commission are the same bounded context or two.

## Decision

**Attribution freeze boundary**: `Modules\Affiliate\Services\AffiliateAttributionService::freezeAttributionForOrder()` runs once, synchronously, inside the same database transaction as Order creation (`Modules\Order\Services\OrderCreationService::executeOrderCreationTransaction()`) — strictly before any payment event can fire. It resolves the visitor's hashed first-party attribution token and/or the order's coupon code exactly once, writes an immutable `affiliate_attributions` row (the *who/how* decision) and an `affiliate_conversions` row (initial status `pending`) with one `affiliate_conversion_items` row per commission-eligible Order Item — the commissionable base and computed commission are calculated from the just-created `OrderItem`'s own immutable `subtotal_minor`/`discount_minor` fields, never from a Checkout-layer intermediate value. Payment confirmation (`ActivateAffiliateConversionOnOrderPaidListener`, listening to `OrderStatusChanged` payment→paid) only ever *activates* this already-frozen snapshot — posting the frozen `commission_amount_minor` into `AffiliatePayableEntry` rows — it never re-resolves attribution or recomputes commission from live Campaign/Click/CommissionRule state. A failure inside the freeze call is caught and reported, never allowed to block real order creation.

This is a deliberately different integration point than initially planned (deep inside `CheckoutOrchestrator`'s per-line snapshot construction, mirroring Vendor commission): source-reading `Modules\Order\Services\OrderSnapshotValidator` during implementation revealed it reconstructs `$validatedSnapshot['lines']` as a *closed* shape that silently drops the very vendor-commission fields `OrderCreationService` reads from it — meaning Vendor commission snapshotting into `order_items` has the same latent gap today. Rather than depend on that already-incomplete pass-through (or expand this delta's scope to fix a pre-existing Marketplace gap), Affiliate attribution freezes at the Order-creation boundary instead, using `OrderItem`'s own guaranteed-populated fields — an equally authoritative point named explicitly as acceptable ("Order creation / Checkout completion boundary") in the Owner Delta itself.

**Customer referral vs. Affiliate program**: `Modules\Customers\Models\{CustomerReferralCode,CustomerReferral}` is a separate, peer-to-peer bounded context, not a special case of `Modules\Affiliate`. A Customer referring a friend is always a non-monetary reward (Loyalty points) issued to a Customer identity; an Affiliate's commission is a cash-payable-bearing relationship with its own approval/suspension lifecycle, target scoping, and payout rail. Conflating the two into one schema would force every Affiliate-shaped column (target_type, commission rules, payout currency) onto simple peer referrals that need none of it.

## Consequences

- Attribution, once frozen, is provably unaffected by a later Campaign/Referral-Code edit or deactivation — proven by `AffiliateAttributionAndCommissionTest`.
- Manual re-attribution (`AffiliateAttributionService::manuallyReattribute()`) never mutates the frozen row; it marks it `superseded_by_attribution_id` and creates a new one, triggering a compensating reversal only if the superseded attribution had already accrued.
- Vendor commission's Checkout-to-`order_items` pass-through gap was identified but deliberately left unfixed — it is a pre-existing Marketplace concern outside this delta's authority boundary, noted here for the record rather than silently worked around.
- `customer_referrals` carries its own qualification policy (first paid Order only, one referrer per referred Customer per Tenant, self-referral blocked) independent of anything in `modules/Affiliate`.

## References

- `modules/Affiliate/Services/AffiliateAttributionService.php`, `modules/Order/Services/OrderCreationService.php`
- `modules/Customers/Services/CustomerReferralService.php`
- `tests/Feature/Affiliate/AffiliateAttributionAndCommissionTest.php`, `tests/Feature/Customers/CustomerReferralServiceTest.php`
