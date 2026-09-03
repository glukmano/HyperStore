# ADR-0112: Payout Allocation Reservation, Directional Payables & Atomic Finalization

## Status
ACCEPTED

## Date
2026-09-03

## Context
When vendors request payouts, available liquidity must be reserved atomically to prevent concurrent overdraws. Marking an entire earning entry as unavailable breaks partial allocations. Furthermore, when payouts complete, subtracting both consumed allocations and payout disbursements would double-subtract the payout from the vendor's economic balance.

## Decision
1. **Sole Economic Truth**: `vendor_payable_entries` is the sole authoritative economic subledger. `payout_request_allocations` represents reservation, source-allocation, and audit linkage only.
2. **Separate Balance Equations**:
   - Global Economic Balance: $\sum(\text{credits}) - \sum(\text{debits})$. (Allocations are NOT in this equation).
   - Source Allocatable Capacity: $\text{source\_net} - \sum \text{allocated} \quad (\text{status} \in \{\text{'reserved'}, \text{'consumed'}\})$.
   - Reserved for Payout: $\sum \text{allocated} \quad (\text{status} = \text{'reserved'})$.
   - Withdrawable Balance: $\text{Available Economic Balance} - \text{Reserved for Payout}$.
3. **Atomic Finalization & Idempotency**:
   - When a payout is paid, a new `payout_disbursement` entry is appended to `vendor_payable_entries` (`source_type = 'payout_request'`, `source_uuid = $payout->uuid`), and allocations transition from `reserved` to `consumed`.
   - Unique constraint `(tenant_id, source_type, source_uuid, entry_type)` guarantees idempotent finalization replay.
4. **Single-Currency Invariant**: All payout requests and allocations are strictly single-currency.

## Consequences
- Partial payout allocations correctly leave residual capacity withdrawable.
- Consumed allocations prevent reuse of earning capacity while zero double-subtraction occurs in balance calculations.
