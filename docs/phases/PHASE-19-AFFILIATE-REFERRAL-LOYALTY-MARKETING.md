# PHASE-19: Affiliate, Referral, Loyalty & Marketing

> Status: COMPLETED
> Master sections: §16 (Affiliate/Marketing), §18 (Loyalty/rewards part)
> Owner Delta applied: 2026-09-05 (24-point correction to the original implementation-ready plan; see ADR-0142, ADR-0143)

## 1. Objective

Deliver a first-party Affiliate program (profiles, referral links, click tracking, attribution, commission, payable subledger, payouts, fraud flags), a separate peer-to-peer Customer referral mechanism, a non-cash Loyalty points program, abandoned-cart marketing reminders, and a rule-based product recommendation engine — reusing the platform's existing payout/subledger/notification/context machinery rather than building parallel systems.

## 2. Included Scope

- **Affiliate**: `modules/Affiliate` — Affiliate profiles (apply/approve/suspend/reject), Campaigns (target scope + attribution strategy + window), Referral Codes, Click tracking via a first-party random attribution token, frozen Attribution snapshots, line-level Conversion Items with immutable commission snapshots, Commission Rules (currency-scoped), a self-contained Payable subledger, Payouts (via the shared orchestrator, see ADR-0142), and rule-based Fraud Flags.
- **Shared payout orchestration**: `App\Core\Payables\{Enums,Contracts,Services\AbstractPayoutOrchestrator}` — extracted from Marketplace's existing `PayoutService`; both Marketplace and Affiliate payouts now run through the identical state machine (ADR-0142).
- **Customer referral**: `modules/Customers` — `CustomerReferralCode`/`CustomerReferral` + `CustomerReferralService`, a distinct peer-to-peer bounded context from Affiliate (ADR-0143).
- **Loyalty**: `modules/Promotions` — `LoyaltyProgram`/`LoyaltyProgramCurrencyRule`/`LoyaltyPointEntry`/`LoyaltyAccountLock` + `LoyaltyService` (multi-currency-safe earn/redeem, pending/maturity/expiry, concurrency-safe redemption).
- **Marketing**: `modules/Cart` abandoned-cart reminders (consent-gated, race-safe) and `modules/Catalog\Services\ProductRecommendationService` (frequently-bought-together / related-by-category / cross-sell, rule-based only).
- **Attribution wiring**: `Modules\Order\Services\OrderCreationService` freezes Affiliate attribution at Order-creation time; `OrderStatusChanged`/`PaymentRefunded`/`PaymentPartiallyRefunded` listeners activate/reverse commission and loyalty points.

## 3. Explicitly Excluded Scope

Wallet, Store Credit, Gift Card stored-monetary-value balances (deferred — requires new `modules/Ledger` account roles); B2B, Auctions, Booking/Services; ML/AI-driven recommendations; a real-time fraud-scoring service or third-party fraud API; automated affiliate-network integrations; multi-tier/MLM affiliate-of-affiliate commission chains; a public "become an affiliate" marketing landing page beyond the functional application form; gamification badges/tiers. Phase-20 is not started.

## 4. Required Skills

`project-governance`.

## 5. Prerequisites

Phase-18 closed (`8b6f8bb`). Marketplace's `VendorPayableEntry`/`PayoutService`/`VendorCommissionRule` pattern (Phase-11) as the structural template. `Modules\Order\Events\OrderStatusChanged`, `Modules\Payment\Events\{PaymentRefunded,PaymentPartiallyRefunded}`, `Modules\Notifications\Services\NotificationDispatchService` (Phase-17/18) as reused integration points.

## 6. Architecture & ADRs

- ADR-0142: Shared Payout Orchestrator Across Payable Beneficiary Types.
- ADR-0143: Affiliate Attribution Freeze Boundary and Customer Referral Separation.
- No second commission/payout/discount engine introduced anywhere in this phase.
- Loyalty never references `modules/Ledger` (points are non-cash, non-withdrawable, discount-entitlement only).

## 7. Database Work

Four new migrations, all additive: `create_affiliate_tables` (13 tables + `coupons.affiliate_id`), `create_customer_referral_tables`, `create_loyalty_tables`, `create_abandoned_cart_reminders_table`. Postgres-specific integrity (CHECK constraints, partial unique indexes, append-only/immutable-economic-field triggers on `affiliate_payable_entries`) mirrors the exact conventions already proven on `vendor_payable_entries`. Verified applying and rolling back cleanly against real PostgreSQL (not just SQLite).

## 8. Backend Work

See §2. Key correctness points: attribution is frozen once at Order-creation time and never recomputed from live Campaign/Click/Rule state; commission is computed from immutable `OrderItem` snapshot fields, line-by-line, per target scope; refunds/manual-reattribution reverse commission via idempotent compensating entries, never mutating the original earning entry; every economic operation is idempotent by a durable source key; Loyalty redemption is concurrency-safe via a dedicated lock-anchor row recomputing the authoritative balance inside the lock.

## 9. Frontend Work

Control Center: `AffiliateManager`, `AffiliateCommissionRuleManager`, `AffiliateCampaignManager`, `AffiliatePayoutManager` (Livewire, `<x-ui.*>` only). Storefront: `AffiliateApplicationForm`, `AffiliateDashboard` under `/account/affiliate`. No custom design system, no new icon library, no React/Vue/Next.

## 10. Security

Referral codes and target scopes are Tenant-validated through one resolver (`AffiliateTargetResolverInterface`), never trusting a raw `target_id`. Visitor identity is a first-party random token (hashed before storage), never a fingerprint. Abandoned-cart marketing only reaches authenticated Customers with `marketing_opt_in = true` — guest carts are never targeted, since no lawful consent signal exists for them today. Cross-currency commission/loyalty conversion is never silent — a missing currency rule means no commission/no earn/no redeem, full stop.

## 11. Package Integrity / Non-Financial Idempotency

No new Composer/npm package introduced. `composer audit`: clean. `npm audit --audit-level=high`: clean.

## 12. Tests

1168 total tests (1136 baseline + 32 new), 4796 assertions, all green. New coverage: shared payout orchestration parity with Marketplace, attribution freeze/strategy/currency-safety/refund-reversal/manual-reattribution, Loyalty multi-currency/pending-maturity/expiry/concurrency (real PostgreSQL), Customer referral qualification policy, abandoned-cart consent/race-safety, recommendation eligibility filtering, and a Phase-19 architecture test suite.

## 13. Documentation

This file, ADR-0142, ADR-0143, `docs/decisions/README.md` updated.

## 14. Acceptance Criteria

- [x] No second payout engine — proven by architecture test and by `AffiliatePayoutOrchestrationTest` passing scenario-for-scenario against Marketplace's own test.
- [x] `modules/Ledger` untouched by Loyalty (architecture-tested).
- [x] Attribution frozen before payment; strategies (first/last/coupon/manual) individually correct.
- [x] Line-level, immutable commission snapshot; partial/full refund reversal; multi-currency-safe (no silent conversion).
- [x] Manual re-attribution never mutates history.
- [x] Loyalty is multi-currency-safe, has pending/maturity/expiry semantics, and concurrency-safe redemption (real PostgreSQL test).
- [x] Customer referral and Affiliate remain distinct schemas.
- [x] Abandoned-cart reminders are consent-gated and race-safe.
- [x] Recommendations are rule-based only, Store-eligibility-respecting.
- [x] Full regression green; PHPStan Level 8 clean; Pint clean; `npm run build` clean; `composer audit`/`npm audit --audit-level=high` clean.
- [x] `PROJECT_MASTER_PLAN.md` untouched; working tree clean at completion.

## 15. Stop Condition

Phase-19 is complete. Do not start Phase-20 without explicit owner instruction.
