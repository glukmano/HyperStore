# Affiliate Module Specification

**Module Namespace**: `Modules\Affiliate`
**Root Path**: `modules/Affiliate/`
**Status**: Active Production Module (PHASE-19)

---

## 1. Overview & Architectural Boundaries

The `Affiliate` module owns Affiliate profiles, referral links/codes, click tracking, attribution, commission calculation, a self-contained payable subledger, payouts, and rule-based fraud flags — the "Affiliate is first-party" requirement of `PROJECT_MASTER_PLAN.md` §16.

### Key Invariants

1. **No second payout engine.** Affiliate payouts (`AffiliatePayoutService`) are a thin adapter over `App\Core\Payables\Services\AbstractPayoutOrchestrator` — the exact same shared state machine Marketplace's `PayoutService` uses. See ADR-0142.
2. **Attribution is frozen once, at Order-creation time**, and never recomputed from live Campaign/Click/CommissionRule state afterward. See ADR-0143.
3. **Commission is line-level and immutable**: `AffiliateConversionItem` snapshots `commissionable_base_minor`/`commission_rate_bps`/`commission_fixed_fee_minor`/`commission_amount_minor` per `OrderItem`, computed once from that item's own frozen `subtotal_minor`/`discount_minor`.
4. **No implicit currency conversion, ever.** A `AffiliateCommissionRule` only ever applies to a commission basis already in that rule's exact currency; no matching rule means no commission for that item.
5. **Visitor identity is a first-party random token**, hashed before persistence — never a browser fingerprint. IP hash / User-Agent are optional fraud signals only.
6. **Domain-owned subledger stays separate from Marketplace's.** `AffiliatePayableEntry` is its own append-only, immutable-economic-fields table — never `VendorPayableEntry`.
7. **Refunds/manual re-attribution reverse via compensating entries** — the original earning entry is never edited or deleted (`AffiliateConversionReversalService`).

## 2. Data Model

`affiliates` → `affiliate_campaigns` → `affiliate_referral_codes` → `affiliate_clicks` (append-only). `affiliate_attributions` (frozen decision, one active row per Order, superseded chain for history) → `affiliate_conversions` (pending at freeze time, accrued on payment=paid, reversed on full refund) → `affiliate_conversion_items` (line-level frozen commission). `affiliate_commission_rules` (currency-scoped, most-specific-first resolution). `affiliate_payable_entries` (append-only subledger) → `affiliate_payout_{batches,requests,request_allocations}` (shared enums from `App\Core\Payables\Enums`). `affiliate_fraud_flags` (non-blocking).

## 3. Attribution Strategies

`first_click` / `last_click` / `coupon` / `manual`, configured on `AffiliateCampaign` (one authoritative location, with a `last_click`/30-day-window platform default when no Campaign applies). `coupon` strategy resolves via `coupons.affiliate_id` (additive column) rather than a stored click. `manual` is an admin-only override (`AffiliateAttributionService::manuallyReattribute()`) that never mutates history — it supersedes the old attribution and reverses its accrual if any had already posted.

## 4. Integration Points

- `Modules\Order\Services\OrderCreationService` calls `AffiliateAttributionServiceInterface::freezeAttributionForOrder()` once, inside the Order-creation transaction, guarded by `app()->bound()` so Order never hard-depends on Affiliate.
- `ActivateAffiliateConversionOnOrderPaidListener` (on `OrderStatusChanged`, payment→paid) activates the frozen conversion into real payable entries.
- `ReverseAffiliateCommissionOnRefundListener` (on `PaymentRefunded`/`PaymentPartiallyRefunded`) posts a proportional compensating reversal.
- `GET /r/{code}` (`AffiliateClickTrackingController`) records the click, mints/refreshes the `hs_aff_token` cookie, and redirects to the referral code's target.

## 5. Reuse

`App\Core\Payables\Services\AbstractPayoutOrchestrator` (shared with Marketplace). `Modules\Notifications` is not directly used by Affiliate today (payout/application state changes surface via Control Center/storefront flash messages only).
