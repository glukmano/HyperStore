# ADR-0105: Single-Currency Journal Balancing & Query-Time Balance Derivation

## Status
Accepted

## Context
Multi-currency commerce requires strict mathematical separation. Storing mixed currencies within a single journal entry or summing multiple currencies into a single balance column produces meaningless numbers and introduces silent FX conversion bugs.

## Decision
1. **Single-Currency Invariant**:
   Every `JournalEntry` balances strictly within a single ISO currency. Every line's currency must equal the journal entry's currency.
2. **Account Currency Restrictions**:
   - If `ledger_account.currency !== null`, only journals matching that exact currency may post to that account.
   - If `ledger_account.currency === null`, the account accepts lines in multiple currencies, but balances are always calculated and returned strictly partitioned by currency.
3. **Pure Query-Time Balance Aggregation**:
   Phase-10 enforces query-time aggregation over `journal_lines`:
   $$\text{Balance} = \sum_{\text{debit lines}} \text{amount\_minor} - \sum_{\text{credit lines}} \text{amount\_minor}$$
   (adjusted for normal balance polarity).
4. **Zero Mutable Balance Caches**:
   No `current_balance_minor` columns or balance materialization tables exist in Phase-10. This ensures 100% mathematical consistency and eliminates cache drift.

## Consequences
- Single-currency journals guarantee exact integer arithmetic with zero floating-point rounding errors.
- Balances are always mathematically derived from immutable journal history.
