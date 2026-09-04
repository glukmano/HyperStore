# ADR-0128: RMA Physical-Restock Integration Contract

## Status
Accepted

## Context
`return_items.restock_action` exists, is set to `'restock'` by `ReturnRequestService` at return-creation time, and is confirmed never read by any consumer today — a true inert stub. `ReturnRefundOrchestrator`/`ReturnRequestService` have zero Inventory references today. Master Section 17 requires Returns to have "ledger effects" and implicitly a restock outcome, but a customer refund must never be conflated with a physical restock decision — they are governed by different facts (money owed vs. physical condition of goods received).

## Decision
1. Restock must trigger at the point of **physical disposition** (received + inspected + condition determined) — never at `ReturnRefundOrchestrator::finalizeRefund()`. Refund and restock remain architecturally independent workflows; finalizing a refund must never itself call any Inventory service.
2. `restock_action`'s value domain is extended (still a free string column, no CHECK constraint added this phase) to: `restock → Inventory receive()/on_hand`; `quarantine → Inventory quarantine()`; `discard → no sellable stock mutation`; `return_to_supplier → no internal sellable restock`. Only `restock` is written by any code today — the other three require the disposition UI/API to actually set them, which is in scope for this phase's backend wiring (not a new UI).
3. Idempotency: stable per-return-line identity — `seller_return_uuid + return_item_id + disposition_operation_uuid` — not keyed solely by `SellerReturn` id, since a return may have multiple items with independent dispositions. Duplicate event delivery must return the same Inventory mutation result, never double-increment.
4. The exact existing `SellerReturn`/`ReturnItem` status value representing "physical disposition reached" is an implementation-preflight source-check (not enumerated by this phase's planning audits) — the architectural decision (decoupled from refund, disposition-triggered) is frozen regardless of which exact status name it turns out to be.

## Consequences
- **Positive**: closes a three-phase-old inert stub without coupling refund timing to physical inventory state, avoiding the exact risk Master Section 17 warns against (return economics ≠ stock truth).
- **Negative**: `discard`/`return_to_supplier`/`quarantine` dispositions have no existing UI trigger — this phase wires the backend contract only; a future UI phase must expose the disposition choice to operators.
