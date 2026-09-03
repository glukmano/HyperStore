# ADR-0103: Transaction-Scoped Source Idempotency, Tenant Integrity & Reversal References

## Status
Accepted

## Context
In a multi-tenant distributed system, cross-tenant data contamination and duplicate financial postings are critical operational risks. If a ledger line in Tenant A references an account in Tenant B, or if a network retry creates a duplicate capture journal, financial statements become corrupted.

## Decision
1. **Transaction-Scoped Source Idempotency**:
   The unique index:
   ```sql
   UNIQUE (tenant_id, source_module, source_type, source_uuid, posting_type)
   ```
   ensures at most one financial journal exists per semantic source event. For Payment events, `source_uuid` is strictly the `PaymentTransaction.uuid`.
2. **Composite Tenant-Aware Foreign Keys**:
   To prevent cross-tenant account leakage at the database level:
   - `ledger_accounts` exposes `UNIQUE (tenant_id, id)`.
   - `journal_entries` exposes `UNIQUE (tenant_id, id)`.
   - `journal_lines` references `(tenant_id, ledger_account_id)` and `(tenant_id, journal_entry_id)` with `ON DELETE RESTRICT`.
3. **Tenant-Safe Reversal References**:
   The self-referential foreign key on `journal_entries` enforces tenant integrity:
   ```sql
   FOREIGN KEY (tenant_id, reverses_journal_entry_id)
   REFERENCES journal_entries(tenant_id, id) ON DELETE RESTRICT
   ```
   A partial unique index `UNIQUE (tenant_id, reverses_journal_entry_id) WHERE reverses_journal_entry_id IS NOT NULL` guarantees that an original journal may be reversed at most once.
4. **No Cascade Deletion**:
   All foreign keys referencing `tenants(id)` use `ON DELETE RESTRICT`. Financial history survives tenant lifecycle operations and cannot be erased via cascade delete.

## Consequences
- Cross-tenant account injection is physically rejected by PostgreSQL.
- Idempotent retries safely look up and return the existing journal entry without creating redundant lines.
