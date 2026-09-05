# ADR-0144: B2B Company Credit Exposure as a Domain-Owned, Append-Only Delta Subledger

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0144                              |
| Status      | Accepted                              |
| Date        | 2026-09-06                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-20                              |

## Context

A B2B Company purchasing on payment terms (Net 30, etc.) must never be allowed to place Orders whose combined outstanding exposure exceeds an approved credit limit — under concurrent Order placement, not just sequentially. The Owner Delta required an unambiguous model: no mutable "outstanding balance" column that different code paths could increment/decrement inconsistently, and every reduction in exposure (release or settlement) must reference exactly which reservation it resolves, so a reservation can never be resolved twice or resolved by the wrong entry.

## Decision

`Modules\B2B\Models\CompanyCreditEntry` is a strictly append-only, immutable row (boot-hook-enforced via `updating`/`deleting` throwing `B2BException`): `entry_type` is one of `reservation` (+exposure), `release`/`settlement` (−exposure, resolving a specific reservation), or `manual_adjustment_credit`/`manual_adjustment_debit`. **There is no outstanding-balance column anywhere** — `outstanding_exposure = SUM(amount_minor)` over every entry for the account, computed live, always. A `release`/`settlement` entry's `reverses_entry_id` must point at the `id` of the `reservation` it resolves; a Postgres partial unique index (`uq_company_credit_entries_one_resolution` on `reverses_entry_id` WHERE `entry_type IN ('release','settlement')`) is the DB-level backstop guaranteeing a reservation is resolved by at most one entry — the application-level check is the first line of defense, the index is the actual source of truth.

Concurrency safety mirrors Phase-19's `LoyaltyAccountLock` pattern exactly: `CompanyCreditAccountLock` (`firstOrCreate` + `lockForUpdate()`) inside `DB::transaction()` wraps both `reserveForOrder()` and `resolveReservation()` (used by both `releaseReservation()` and `settleReservation()`). The reservation boundary is fixed at exactly one point: inside `OrderCreationService::executeOrderCreationTransaction()`, immediately after `Order::create()`, before the OrderItem-creation loop — inside the same transaction that wraps the whole method. An `InsufficientCompanyCreditException` here is deliberately **not** caught, so it propagates and rolls back the entire Order-creation transaction.

A refund issued after an invoice has already settled does **not** post a new Company-credit entry at all — it is handled entirely by normal Payment/accounting semantics, since the credit-exposure subledger's only concern is "is this Company currently over its approved limit," not revenue recognition.

## Consequences

- Caught a real bug during implementation: `CompanyCreditService::reserveForOrder()`/`resolveReservation()` initially called `lockForUpdate()` **without** wrapping in `DB::transaction()`, meaning the row lock had zero real effect (Postgres releases a lock the instant a single query outside an explicit transaction completes) — two concurrent $70,000 reservations against a $100,000 limit both succeeded. Fixed by wrapping both methods' entire critical sections in `DB::transaction()`. This was caught only because the Owner-Delta-mandated real-PostgreSQL concurrency test (`PostgreSqlCompanyCreditConcurrencyTest`) was actually written and run, not treated as a formality.
- Like Vendor/Affiliate payables (ADR-0142), this subledger is intentionally **not** posted into `modules/Ledger`'s double-entry system — it is an internal exposure-limit control, not customer-facing spendable money or platform revenue recognition.
- Company membership (`CompanyUser`) is the sole source of truth for which Company a User belongs to — no `customer_profiles.company_id` duplicate column exists, eliminating an entire class of synchronization bugs.

## References

- `modules/B2B/Models/{CompanyCreditEntry,CompanyCreditAccountLock}.php`, `modules/B2B/Services/{CompanyCreditService,CompanyOrderCreditHook}.php`
- `modules/Order/Services/OrderCreationService.php` (reservation boundary)
- `tests/Feature/B2B/CompanyCreditServiceTest.php`, `tests/Concurrency/PostgreSqlCompanyCreditConcurrencyTest.php`
