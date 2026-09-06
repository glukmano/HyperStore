# ADR-0150: Store Value Financial Architecture — Ledger Integration

| Field       | Value                                |
|-------------|---------------------------------------|
| ID          | ADR-0150                              |
| Status      | Accepted                              |
| Date        | 2026-09-06                            |
| Deciders    | Project Lead, Platform Architect      |
| Phase       | PHASE-21                              |

## Context

Unlike Vendor/Affiliate payables and B2B Company credit (self-contained subledgers, ADR-0142/ADR-0144), Wallet/Store Credit/Gift Card balances are real customer-facing monetary liabilities and must integrate with `modules/Ledger`'s double-entry system. The Owner Delta explicitly forbade assuming a Ledger counterpart account (`payment_clearing` was only ever illustrative) and required a real source audit of the actual Phase-09/Phase-10 posting model before deriving the correct counterparts.

## Source Audit Findings

`modules/Ledger/Enums/SystemAccountRole.php` had exactly two cases prior to this phase: `PAYMENT_CLEARING` (asset, normal balance debit) and `CUSTOMER_FUNDS_LIABILITY` (liability, normal balance credit). `Modules\Ledger\Jobs\PostPaymentFinancialMovementJob` (triggered by `PaymentEventAdapter` listening to `PaymentCaptured`/`PaymentRefunded`/`PaymentPartiallyRefunded`) posts exactly two shapes: a **capture** movement (Debit `payment_clearing`, Credit `customer_funds_liability`) and a **refund** movement (the exact reversal). A repository-wide grep for every reference to `customer_funds_liability`/`SystemAccountRole::` confirmed **no other code path anywhere in the codebase ever debits `customer_funds_liability`** — there is no revenue-recognition posting, no fulfillment-triggered liability release, at all. The platform's Ledger model is deliberately minimal: it tracks cash-in-transit (`payment_clearing`) against an undifferentiated "we owe the customer something" bucket (`customer_funds_liability`), and stops there. `LedgerAccountRegistry::ensureRequiredSystemAccounts()` provisions exactly one system account per role per Tenant, `currency: null` (accepts any draft currency).

This confirms the Owner Delta's suspicion: assuming `payment_clearing` as the Store Value counterpart would have been wrong on two counts — Store Value issuance does not, by itself, move cash (the cash side is already handled by whatever ordinary Order payment funded the purchase), and the model has no revenue/expense account to fall back on for the genuinely non-cash operations (manual issuance, breakage).

## Decision

**Four new `SystemAccountRole` cases**, added additively (`modules/Ledger/Enums/SystemAccountRole.php`, provisioned by `LedgerAccountRegistry::ensureRequiredSystemAccounts()`):

- `WALLET_LIABILITY`, `STORE_CREDIT_LIABILITY`, `GIFT_CARD_LIABILITY` (liability, normal balance credit) — three distinct roles, never one generic "store value liability," because Wallet, Store Credit, and Gift Card balances carry different regulatory/accounting treatment (gift-card breakage rules differ from store-credit expiration rules) even though they share one domain engine (`modules/Wallet`).
- `STORE_VALUE_NON_CASH_ADJUSTMENT` (expense, normal balance debit) — the smallest explicit accounting extension needed for the operations that have no cash counterpart and that the pre-existing model has no account for: manual issuance/clawback and expiration breakage. Introduced narrowly, per Owner Delta §18, rather than silently inventing an assumed counterpart.

**Posting matrix** (`Modules\Wallet\Services\StoreValueLedgerPostingService`, the single method that performs both the domain `StoreValueEntry` write and the Ledger posting inside one transaction, per Owner Delta §17):

| `StoreValueEntry.entry_type` | Debit | Credit | Rationale |
|---|---|---|---|
| `issue` (funded by a real Order — Gift Card sale, Wallet/Store Credit top-up) | `customer_funds_liability` | `{instrument}_liability` | Reclassifies the generic liability the ordinary Payment-capture posting already created into the Store-Value-specific bucket. The cash side (`payment_clearing`) was already posted by the unmodified, existing capture flow — zero double-counting. |
| `issue` (manual, no real cash — e.g. CS-granted Store Credit) | `store_value_non_cash_adjustment` | `{instrument}_liability` | |
| `capture` (a `hold` placed at Checkout converts to final spend on Order/payment success) | `{instrument}_liability` | `customer_funds_liability` | Relieves the Store Value liability, folding it back into the new Order's own generic "goods owed" bucket — symmetric with how an ordinary Order capture credits `customer_funds_liability`. |
| `refund_credit` (an Order refund issued as Store Credit/Wallet credit, or a tender-allocation refund restoring a prior `capture`) | `customer_funds_liability` | `{instrument}_liability` | Same reclassification shape as `issue`, sourced from the refund event. |
| `expire` (unused balance permanently forfeited — breakage) | `{instrument}_liability` | `store_value_non_cash_adjustment` | |
| `manual_adjustment_credit` | `store_value_non_cash_adjustment` | `{instrument}_liability` | |
| `manual_adjustment_debit` | `{instrument}_liability` | `store_value_non_cash_adjustment` | |

**`hold` and `release` never post to Ledger at all** (Owner Delta §16) — they are a pre-commercial reservation pair that nets to zero if released; only `capture` (the point the reservation becomes final) is a real economic event.

**Atomicity** (Owner Delta §17): every row in the table above is posted by exactly one `StoreValueLedgerPostingService` method that writes the domain `StoreValueEntry` and calls `LedgerPostingServiceInterface::post()` inside the same `DB::transaction()`, sharing one idempotency identity (`source_type`, `source_uuid`, `entry_type`) on both sides — there is no caller-visible way to write one without the other.

## Consequences

- Gift Card sale is correctly modeled as a liability creation, never revenue recognition, consistent with real accounting practice and the Owner Delta's explicit instruction.
- The platform still has no revenue-recognition account for ordinary (non-Store-Value) Orders — this is a pre-existing, out-of-scope gap, not something this phase silently fixes; `STORE_VALUE_NON_CASH_ADJUSTMENT` is scoped narrowly to Store Value's own non-cash operations, not a general-purpose revenue account.
- A future real payment-gateway refund for a Store-Value-tendered Order still routes the external-gateway slice through the existing, unmodified `PaymentRefundService`; only the Store-Value slice is handled by this new posting matrix (see `docs/modules/WALLET.md` for the mixed-tender refund orchestration).

## References

- `modules/Ledger/Enums/SystemAccountRole.php`, `modules/Ledger/Services/LedgerAccountRegistry.php`
- `modules/Wallet/Services/{StoreValueLedgerPostingService,StoreValueService}.php`
- `tests/Feature/Ledger/LedgerAccountProvisioningTest.php`, `tests/Feature/Wallet/StoreValueLedgerPostingTest.php`
