# PHASE-20: B2B / Wholesale / Auctions / Booking

> **Authority**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md)
> **Status**: COMPLETED
> **Active Dates**: 2026-09-06
> **Owner Delta**: Two rounds of owner corrections applied before and during implementation (23-point program-level delta, 10-point Phase-20-specific final delta) — see `docs/decisions/ADR-0144` through `ADR-0146` for the resulting architectural decisions.

---

## 1. Objective

Add B2B/Wholesale commerce (Company accounts, negotiated Quote pricing, credit terms), Auction (bid-based) commerce, and Booking (appointment/resource-slot) commerce — each integrating through the **existing** Catalog/Pricing/Checkout/Order/Payment/Inventory pipeline, with zero second commerce-core engine.

## 2. Included Scope

- `modules/B2B`: `Company`/`CompanyUser` (sole membership source of truth), Company-scoped RBAC (`owner`/`buyer`/`approver`), wholesale/tier pricing wired into the live Checkout pricing pass via `CustomerGroupResolverInterface`, RFQ/Quote lifecycle with server-authoritative negotiated pricing frozen per-CartLine (`quote_line_id`), append-only Company credit-exposure subledger (`CompanyCreditEntry`) with hard reservation/release/settlement semantics, payment-terms/invoice-later Orders.
- `modules/Auctions`: `Auction`/`Bid` (genuinely append-only, immutable Bid rows; DB-triggered defense-in-depth), database-authoritative bid placement and close comparison, Auction/normal-commerce eligibility gate, Inventory reservation at activation with handoff into the ordinary Order reservation lifecycle, winner-Checkout settlement bound to one bidder.
- `modules/Booking`: `BookingService`/`BookingResource`/`BookingSlot`/`Booking`, DST-safe slot generation, live (never cached) capacity accounting under a row lock, unconditional per-Checkout-session hold idempotency surviving held→confirmed.
- Control Center screens for all three domains; Storefront pages/widgets for Company account, Quote request, Auction bidding, Booking scheduling.
- `Phase20ArchitectureTest` proving no second Checkout/Order/Payment/Pricing engine, Bid-row immutability, single Company-membership source of truth, company-scoped (never global-Spatie) roles, no floating-point money.

## 3. Explicitly Excluded Scope

- Phase-21 (Digital Products/Subscriptions/Wallet/Store Credit/Gift Cards) — planned only, not implemented.
- Phase-23 feature-flag/Pennant configuration.
- B2B full accounts-receivable/dunning workflow (only the credit-limit gate).
- Auction anti-sniping/auto-extension; Booking deposits/partial payment.
- POS/Omnichannel.

## 4. Required Skills

- `project-governance`.

## 5. Prerequisites

- Phase-19 closed (`b86ab30`).

## 6. Architecture & ADRs

- `ADR-0144`: B2B Company Credit Exposure as a Domain-Owned, Append-Only Delta Subledger.
- `ADR-0145`: Auction Bid Immutability + Row-Locking + DB-Authoritative Time.
- `ADR-0146`: Booking Capacity Model — Materialized Slots, No Cached Counters.

## 7. Database Work

New tables (all additive, PostgreSQL, minor-unit integers): `customer_groups`; `companies, company_users, company_credit_accounts, company_credit_account_locks, company_credit_entries, company_invoices, quotes, quote_lines`; `auctions, bids`; `booking_services, booking_resources, booking_service_resources, availability_rules, availability_exceptions, booking_slots, bookings`. Additive columns: `price_books.customer_group_id` (retroactive FK), `cart_lines.quote_line_id`, `orders.company_id/payment_terms_days/quote_id`, `order_items.quote_line_id/auction_id/winning_bid_id/winning_bid_amount_minor/auction_currency_snapshot/reserve_met/booking_id/booking_slot_starts_at_snapshot/booking_timezone_snapshot`, `checkout_sessions.auction_id`.

Key constraints: `uq_company_credit_entries_one_resolution` — partial unique index on `company_credit_entries(reverses_entry_id)` where `entry_type IN ('release','settlement')`, enforcing exactly-once reservation resolution. `bids` — Postgres trigger `prevent_bid_mutation()` blocking `UPDATE`/`DELETE`. `bookings` — unconditional `unique(booking_slot_id, checkout_session_uuid)` (no status filter). `booking_slots` — `unique(booking_service_id, booking_resource_id, starts_at)`.

## 8. Backend Work

Non-invasive `app()->bound()`-guarded hooks at the Order-creation/Checkout boundary (Affiliate pattern, reused verbatim): `CompanyOrderCreditHookInterface` (NOT try/caught — a credit-limit violation must roll back Order creation), `AuctionOrderSettlementHookInterface`, `BookingOrderConfirmationHookInterface`, `BookingHoldHookInterface`, `AuctionEligibilityGateInterface`. Price-override seam in `CheckoutPricingOrchestrator::calculate()` for Quote-negotiated and Auction-winning prices — both no-ops for every non-B2B/non-Auction cart.

## 9. Frontend Work

Control Center: `CompanyManager`, `QuoteManager`, `CompanyCreditManager`, `AuctionManager`, `BookingResourceManager`. Storefront: `/account/company`, `/account/quotes`, Auction product template with live bid widget, Booking product template with slot picker. Theme `supported_product_type_templates` populated with `auction`/`booking` for the first time.

## 10. API Work

No new REST/Sanctum surface — all interaction is via Livewire, matching every prior phase's storefront/Control-Center pattern.

## 11. Security

Cross-Company isolation, Company-role privilege-escalation rejection (a role in Company A confers no authority in Company B), Quote-price-injection rejection (no client-suppliable `cart_line_id → price`), Auction bid-forgery/bid-after-close rejection (DB time), Auction normal-cart-purchase rejection while active, winner-Checkout rejection for non-winners, Booking slot-tampering/double-booking/duplicate-hold rejection.

## 12. Tests

31 new Feature/unit tests + 4 real-PostgreSQL concurrency tests (Company credit, Auction bidding, Booking capacity ×2) + 5-test `Phase20ArchitectureTest`. Full Phase 01–20 regression: **1213/1213 passing, 4909 assertions**. Real-Postgres full concurrency suite: **120/121** (the one failure is the pre-existing, unrelated `test_race_b_concurrent_custom_domain_claim`, documented in `PHASE-19...md` §16 — not touched, not claimed green). PHPStan Level 8: 0 errors. Pint: clean. `npm run build`: clean. `composer audit` / `npm audit --audit-level=high`: 0 vulnerabilities.

## 13. Documentation

`docs/modules/B2B.md`, `docs/modules/AUCTIONS.md`, `docs/modules/BOOKING.md` (new). `docs/DEPENDENCIES.md` — unchanged (no new package).

## 14. Acceptance Criteria

- [x] `modules/B2B`, `modules/Auctions`, `modules/Booking` exist, integrating through existing Catalog/Pricing/Checkout/Order/Payment — zero second commerce-core engine (architecture-tested).
- [x] Wholesale/tier pricing resolves through the live Checkout pricing pass; previously-hardcoded `customerGroupId: null` gap closed; unaffected when no Company is present.
- [x] Company credit exposure is a pure append-only delta model, no mutable outstanding-balance column, every release/settlement references its reservation via `reverses_entry_id`; concurrency proven safe under real PostgreSQL.
- [x] Company membership has exactly one source of truth (`company_users`); Company-local roles are never a global Spatie permission (architecture-tested).
- [x] Quote-derived pricing is server-resolved only; accepted-Quote checkout rejects `applyCoupon()` by default.
- [x] `Bid` rows are never mutated after creation (architecture-tested); winner derived solely from `Auction.current_bid_id`.
- [x] Auction bidding/closing use database-authoritative time; real concurrency test proves no lost update.
- [x] Auction Product not purchasable via ordinary Add-to-Cart while active; winner Checkout bound to the winning bidder; Inventory reserved at activation, handed off at settlement.
- [x] Booking capacity is a live `COUNT(*)` under a row lock, no cached counter; duplicate hold requests never create a second row; slot generation DB-unique-idempotent; DST transition tested.
- [x] Every new economic entry idempotent by durable source key.
- [x] Full regression green; PHPStan/Pint/Vite/audits clean.
- [x] `PROJECT_MASTER_PLAN.md` untouched; working tree clean at completion commit.

## 15. Stop Condition

Phase-20 acceptance criteria satisfied. Full test/lint/audit gate green. **STOP** — Phase-21 (Digital/Subscriptions/Wallet/Store Credit/Gift Cards) remains planned-but-not-implemented pending separate, explicit owner authorization. Phase-22 not started.
