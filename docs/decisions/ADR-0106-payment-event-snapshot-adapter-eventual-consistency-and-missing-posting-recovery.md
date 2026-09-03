# ADR-0106: Payment Event Snapshot Adapter, Eventual Consistency & Missing-Posting Recovery

## Status
Accepted

## Context
When decoupling the Payment module from the Ledger module, event serialization becomes a critical architectural risk. Payment events currently carry Eloquent models (`Payment`, `PaymentTransaction`). If a queued listener serializes these models directly, Laravel will re-hydrate them later, exposing the ledger consumer to mutated database records or deleted relationships.

## Decision
1. **Single Asynchronous Boundary**:
   The Payment event listener is synchronous and lightweight. It immediately extracts primitive scalar values into an immutable `PaymentFinancialMovementDTO` and dispatches a single `ShouldQueue` job (`PostPaymentFinancialMovementJob`).
2. **Authoritative Occurrence Timestamp Bridge**:
   `PaymentTransaction` does not currently contain a separate `succeeded_at` timestamp. As an explicit bridge contract, the synchronous adapter captures `PaymentTransaction.updated_at` converted explicitly to UTC CarbonImmutable at event dispatch time.
3. **Centralized Eligibility Policy**:
   `PaymentMovementEligibilityPolicy` centralizes posting qualification:
   - Eligible: `purchase`, `capture`, `refund` with `status === 'success'` and `amount_minor > 0`.
   - Ineligible (No-Op): `authorize`, `void`, `zero_total_settlement`, `failure`, `unknown`, `pending`.
4. **Audit and Replay Tooling Separation**:
   - `ledger:audit-unposted-payment-transactions`: Read-only diagnostic command identifying successful transactions lacking ledger journals.
   - `ledger:replay-unposted-payment-transactions`: Explicit repair command that reconstructs the immutable DTO and replays through `LedgerPostingService` idempotently.

## Consequences
- Queued ledger jobs contain only scalar data, preventing model re-hydration side effects.
- Eventual consistency is fully recoverable via audit and replay commands.
