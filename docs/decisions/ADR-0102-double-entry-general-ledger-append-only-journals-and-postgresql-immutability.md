# ADR-0102: Double-Entry General Ledger, Append-Only Journals & PostgreSQL Immutability

## Status
Accepted

## Context
Financial ledgers must provide verifiable auditability and mathematical correctness. Pseudo-ledgers that simply increment or decrement mutable balance fields on customer or account tables are prone to lost updates, undetectable tampering, and reconciliation failures. Furthermore, allowing posted financial entries to be updated or deleted violates accounting standards.

## Decision
1. **Strict Double-Entry Bookkeeping**:
   Every posted `JournalEntry` contains $\ge 2$ `JournalLine`s and strictly satisfies:
   $$\sum \text{Debit Minor} - \sum \text{Credit Minor} = 0$$
   within a single ISO currency. Unbalanced entries are rejected atomically.
2. **Append-Only Schema**:
   `journal_entries` and `journal_lines` contain no `updated_at` column. Once inserted, rows are permanently immutable.
3. **PostgreSQL Immutability Triggers**:
   Database-level triggers enforce immutability at the storage layer:
   ```sql
   CREATE TRIGGER trg_journal_entries_immutable
   BEFORE UPDATE OR DELETE ON journal_entries
   FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_mutation();

   CREATE TRIGGER trg_journal_lines_immutable
   BEFORE UPDATE OR DELETE ON journal_lines
   FOR EACH ROW EXECUTE FUNCTION prevent_posted_journal_mutation();
   ```
   Application model hooks provide secondary defense, but PostgreSQL triggers are the authoritative guard.
4. **Three-Table Persistence Model**:
   The domain persistence footprint is strictly limited to three tables:
   - `ledger_accounts`
   - `journal_entries`
   - `journal_lines`

## Consequences
- Posted financial records can never be altered or erased by application bugs or direct SQL queries.
- Rollback migrations must explicitly drop immutability triggers and functions before dropping tables.
