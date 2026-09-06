# ADR-0156: Register / Register-Session / Append-Only Cash Movement Model

## Status
Accepted — Phase-22.

## Context
Owner Delta §1/§2/§3 required: an exact Store→Market resolution per Register (no ambiguous default InventorySource), hard-fail (not soft) POS Order context validation, and a cash-drawer model with a single source of truth — no two independently-mutable "opening cash" values, and no `closing_count` movement (a physical count is not a cash-in/out event).

## Decision
- `pos_registers.store_market_id` (FK to `store_markets`, required `is_active=true`) resolves the exact Tenant→Store→Market→Currency chain; `inventory_source_id` is owned directly by the Register (not an ambiguous Store-level default).
- `pos_register_sessions` has a DB-enforced partial unique index (`register_id WHERE status='active'`) — one active session per register, caught at the constraint layer under real concurrency, not merely an app-level check.
- The `opening_float` `PosCashMovement` row is authoritative; `pos_register_sessions.opening_cash_minor` is written in the **same transaction** as that movement and never independently edited afterward.
- `closing_count` is **not** a `CashMovementType` — a physical count is a closing **snapshot** (`closing_cash_counted_minor`/`closing_cash_expected_minor`/`closing_variance_minor` on the session), never a movement contributing to the derived expected-cash sum.
- Expected cash is always `SUM(amount_minor)` over `opening_float/sale_cash_in/refund_cash_out/paid_in/paid_out` movements for a session — never a cached counter.
- `PosOrderContextHookInterface::validateAndFreezePosContext()` is invoked from `OrderCreationService::createFromCheckout()` **without** a try/catch, mirroring the B2B `CompanyOrderCreditHookInterface` precedent — a closed/invalid session, unauthorized cashier, or Store/Market mismatch rolls back the entire Order-creation transaction.

## Consequences
- Register/session/cash-drawer state has exactly one source of truth at every layer; no reconciliation ambiguity is possible by construction.
- A POS Order can never exist with an invalid, closed, or mismatched register context — verified atomically, not after the fact.
