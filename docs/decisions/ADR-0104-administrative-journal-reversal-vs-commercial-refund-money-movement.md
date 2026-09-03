# ADR-0104: Administrative Journal Reversal vs Commercial Refund Money Movement

## Status
Accepted

## Context
A frequent architectural fallacy in commerce software is conflating customer refunds with accounting journal reversals. A customer refund represents a real commercial cash outflow triggered by customer returns, order cancellation, or adjustments. In contrast, an administrative journal reversal is an accounting correction that voids an erroneous or duplicated journal entry.

## Decision
1. **Clear Conceptual Separation**:
   - **Commercial Refund**: An independent financial movement that debits `customer_funds_liability` and credits `payment_clearing` for the refund transaction amount.
   - **Administrative Reversal**: An accounting correction that references an original journal via `reverses_journal_entry_id` and posts exact inverse lines.
2. **Append-Only Reversals**:
   An original journal is never updated when reversed. Its status is derived by the presence of a reversal journal:
   $$\text{isReversed} \iff \exists j \text{ with } j.\text{reverses\_journal\_entry\_id} = \text{original.id}$$
3. **Refund Event Independence**:
   A refund journal does *not* require the prior capture journal to already exist in the ledger. Because queue delivery is asynchronous, an authoritative successful refund transaction posts immediately on its own merits, trusting Phase-09 monetary limits.

## Consequences
- The original journal remains permanently immutable.
- Refunds do not corrupt or rewrite capture records.
- Asynchronous out-of-order event delivery between capture and refund cannot lock or abort either transaction.
