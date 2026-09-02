# ADR-0096: Payment Aggregate Obligation & PaymentTransaction Attempt State Machines

## Status
Accepted

## Context
Payment lifecycles must balance the overall financial status of an order obligation against the transient, attempt-specific outcomes of individual gateway calls. Conflating these two levels leads to premature termination of the payment obligation upon a single card decline.

Additionally, two-step authorization workflows support incremental or partial captures (e.g. capturing funds as individual shipments leave the warehouse). The aggregate state must handle partial captures without premature completion.

## Decision
1. **Payment Aggregate State Machine**:
   - States: `pending`, `authorized`, `captured`, `partially_refunded`, `refunded`, `cancelled`.
   - The ambiguous `failed` state is eliminated. An attempt decline leaves `Payment` in `pending`.
2. **Partial Capture Invariants**:
   - For an authorization flow, while $0 < \text{captured\_amount\_minor} < \text{payment.amount\_minor}$, `Payment.status` remains `authorized`.
   - `Payment.status` transitions from `authorized` to `captured` **only when** $\text{captured\_amount\_minor} === \text{payment.amount\_minor}$.
   - During partial capture, `Order.payment_status` remains `authorized` and only projects to `paid` upon full capture.
3. **PaymentTransaction State Machine**:
   - Persisted states: `pending`, `action_required`, `success`, `failure`, `unknown`.
   - The transient status `still_pending` is an outcome of a reconciliation check, not a persisted transaction state. If reconciliation returns `still_pending`, the persisted status remains `unknown`.
4. **Strict Numerical Invariants**:
   - $0 \le \text{authorized\_amount\_minor}$
   - $0 \le \text{captured\_amount\_minor} \le \text{payment.amount\_minor}$
   - $0 \le \text{refunded\_amount\_minor} \le \text{captured\_amount\_minor}$
   - $\text{captured\_amount\_minor} \le \text{authorized\_amount\_minor}$ (for authorization flows)

## Consequences
- Clean separation between attempt failures and commercial obligation completion.
- Exact accounting for partial captures and partial refunds without status regression.
