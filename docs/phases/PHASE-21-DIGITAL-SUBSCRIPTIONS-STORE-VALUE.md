# PHASE-21: Digital Products / Subscriptions / Wallet / Store Credit / Gift Cards

> **Authority**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md)
> **Status**: COMPLETED
> **Active Dates**: 2026-09-06
> **Owner Delta**: Implemented under the coordinated PHASE-20+21 program plan, authorized to proceed after Phase-20's own acceptance report and a source audit of the actual Ledger posting model (ADR-0150).

---

## 1. Objective

Add Digital Product delivery (entitlements, secure downloads, license keys), Subscriptions (recurring billing reusing the ordinary Checkout/Order/Payment pipeline), and a shared Store Value engine (Wallet, Store Credit, Gift Cards) with Ledger-integrated hold→capture/release checkout semantics — zero second commerce-core engine.

## 2. Included Scope

- `modules/DigitalDelivery`: `CustomerEntitlement` (shared with Subscriptions), `DigitalAsset`, `DigitalAccessLog`, `LicenseKeyPool`; signed-URL secure delivery with atomic reserve-before-stream consumption; SKIP-LOCKED license allocation backstopped by a DB partial unique index.
- `modules/Subscriptions`: `SubscriptionPlan`/`Subscription`/`SubscriptionRenewalAttempt`; claim-before-charge renewal reusing the existing Checkout/Order/Payment pipeline; the exact decided change scope (cancel-now/at-period-end, plan-change-at-next-renewal, payment-method replacement, grace reactivation); finite dunning retries.
- `modules/Payment` extension: `RecurringPaymentGatewayInterface` (backwards-compatible), `CustomerPaymentMethod`, `PaymentInitiationService::initiateOffSessionPayment()`.
- `modules/Wallet`: shared Store Value engine (`StoreValueAccount`/`StoreValueEntry`), hold→capture/release lifecycle, atomic Ledger posting (4 new `SystemAccountRole` cases), mixed-tender Checkout integration (`amountDueMinor`), deterministic tender-allocation refund orchestration.
- `modules/GiftCards`: thin, delegates to Wallet; plaintext code never persisted.
- Control Center + Storefront UI for all four modules.
- `Phase21ArchitectureTest` proving no second Checkout/Order/Payment/Ledger engine, Wallet≠Loyalty, Store Value≠Coupon, no floating-point money.

## 3. Explicitly Excluded Scope

- Phase-22 (POS/Omnichannel) — not started.
- Phase-23 feature-flag/Pennant configuration.
- Real third-party payment gateway adapters (only `FakePaymentGateway` exists/extended).
- Cash withdrawal from Wallet (ADR-0152).
- Proration on subscription plan changes; pause/resume; quantity-change subscriptions.

## 4. Required Skills

- `project-governance`, `money-ledger`.

## 5. Prerequisites

- Phase-20 closed (`beb4ab2`).

## 6. Architecture & ADRs

- `ADR-0147`: Shared Digital/Subscription Entitlement Model.
- `ADR-0148`: Subscription Renewal Reuses the Ordinary Pipeline.
- `ADR-0149`: Recurring Payment Capability Interface.
- `ADR-0150`: Store Value Ledger Integration (the central Phase-21 ADR, includes the mandated Ledger source audit).
- `ADR-0151`: Mixed Tender Checkout — amountDueMinor Extension.
- `ADR-0152`: Wallet Closed-Loop, Non-Withdrawable.
- `ADR-0153`: Subscription Billing-Period Serialization.

## 7. Database Work

New tables: `digital_assets, digital_access_logs, license_key_pools, customer_entitlements`; `customer_payment_methods, subscription_plans, subscriptions, subscription_renewal_attempts`; `store_value_accounts, store_value_account_locks, store_value_entries, order_payment_tender_allocations, refund_tender_allocations`; `gift_cards`. Additive columns: `order_items.{entitlement_terms_snapshot, subscription_id, billing_period_start_snapshot, billing_period_end_snapshot, plan_snapshot}`, `checkout_sessions.{store_value_applied_minor, store_value_hold_refs}`, `orders.amount_due_minor`. New `SystemAccountRole` cases (Ledger, enum-only, additive).

Key constraints: `uq_license_key_pools_one_order_item` (partial unique on `assigned_order_item_id`); `uq_subscription_renewal_period` (unconditional unique on `subscription_id, billing_period_start`); `uq_store_value_entries_one_resolution` (partial unique on `reverses_entry_id` for capture/release); `uq_customer_entitlements_source`.

## 8. Backend Work

Non-invasive `PaymentCaptured`-event listeners (never at Order-creation time): `GrantEntitlementsOnPaymentCaptured`, `IssueGiftCardOnPaymentCaptured`, `ConvertStoreValueHoldOnPaymentCaptured`. `CheckoutOrchestrator::applyStoreValue()`/`removeStoreValue()`; `PaymentInitiationService::initiateOffSessionPayment()`; `LicenseKeyAllocationService` (SKIP LOCKED + savepoint-protected recovery); `SubscriptionRenewalService` (claim-before-charge).

## 9. Frontend Work

Control Center: `DigitalAssetManager`, `LicenseKeyPoolManager`, `SubscriptionPlanManager`, `SubscriptionManager`, `StoreValueAccountManager`, `GiftCardManager`. Storefront: `/account/downloads`, `/account/subscriptions`, `/account/wallet`, a Gift Card redemption widget, and a Checkout-step Store Value apply widget.

## 10. API Work

No new REST/Sanctum surface — Livewire + one signed-route HTTP controller (`DigitalDownloadController`), matching prior-phase convention.

## 11. Security

Digital: unauthorized/expired/revoked/exhausted-entitlement rejection, cross-Tenant rejection, no raw asset id/path in any URL. License: DB-enforced one-key-per-OrderItem. Recurring payment: credentials never stored raw. Store Value: guessed Gift Card code rate-limited, balance never client-trusted (always `SUM(amount_minor)`), cross-currency/cross-Tenant rejection.

## 12. Tests

51 new Feature/unit tests (across Wallet, GiftCards, DigitalDelivery, Subscriptions, mixed-tender Checkout, Phase-21 architecture) + 4 new real-PostgreSQL concurrency tests (Store Value ×3 instrument types, License Allocation, Subscription Renewal, Digital Download). Full Phase 01–21 regression: **1253/1253 passing, 5020 assertions**. Real-Postgres full concurrency suite: **127/128** (the one failure is the pre-existing, unrelated `test_race_b_concurrent_custom_domain_claim`). PHPStan Level 8: 0 errors. Pint: clean. `npm run build`: clean. `composer audit`/`npm audit --audit-level=high`: 0 vulnerabilities.

## 13. Documentation

`docs/modules/{DIGITAL-DELIVERY,SUBSCRIPTIONS,WALLET,GIFT-CARDS}.md` (new). `docs/DEPENDENCIES.md` — unchanged (no new package).

## 14. Acceptance Criteria

- [x] `modules/DigitalDelivery`, `modules/Subscriptions`, `modules/Wallet`, `modules/GiftCards` exist, integrating through existing Catalog/Pricing/Checkout/Order/Payment/Ledger — zero second commerce-core engine (architecture-tested).
- [x] Digital files never served from a public path; every download entitlement-gated and signed-URL-authorized; download-count consumption reserved atomically under a row lock before streaming (real-Postgres concurrency proven).
- [x] License-key allocation backstopped by a DB unique constraint; duplicate processing returns the existing key; two concurrent Orders racing the last key — exactly one wins (real-Postgres concurrency proven; a genuine savepoint-handling bug was found and fixed here).
- [x] Base `PaymentGatewayInterface` unmodified; recurring capability via a new optional interface; every existing gateway continues to compile/function unchanged.
- [x] Subscription-renewal serialization begins before any charge; no duplicate renewal Order; `unknown` outcome recorded explicitly, never inferred (real-Postgres concurrency proven).
- [x] Phase-21 subscription-change scope implemented exactly as decided — no open "evaluate later" language.
- [x] Store Value integrates with `modules/Ledger`; every final economic event posts atomically with its domain entry; the real Ledger counterpart accounts were derived from a source audit, documented in ADR-0150.
- [x] Store Value application at Checkout is a `hold`, converted to `capture` only on success or `release` on failure/cancellation.
- [x] Wallet confirmed non-cash/closed-loop (ADR-0152); Wallet/Loyalty/Store Credit/Coupons remain four architecturally distinct concepts (architecture-tested).
- [x] Gift Card is a genuinely distinct Store Value instrument from the base model onward (architecture-tested); partial spend, later customer-claim, and guest-checkout spend all supported without merging into another account.
- [x] Store Value cannot double-spend under real PostgreSQL concurrency (Wallet/Store Credit/Gift Card, three scenarios).
- [x] Refunds preserve original tender allocation (deterministic largest-remainder rounding, snapshotted, idempotent on retry).
- [x] No implicit currency conversion anywhere in Store Value.
- [x] Full regression green; PHPStan/Pint/Vite/audits clean.
- [x] `PROJECT_MASTER_PLAN.md` untouched; working tree clean at completion commit.

## 15. Stop Condition

Phase-21 acceptance criteria satisfied. Full test/lint/audit gate green. **STOP** — Phase-22 not started, per every prior phase's own stop condition.
