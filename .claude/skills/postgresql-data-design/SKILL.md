---
name: postgresql-data-design
description: Enforces PostgreSQL relational data integrity, transactions, concurrency locking, indexing, and migration safety. Use when designing schemas or modifying database structures.
---

# PostgreSQL Data Design & Migration Safety

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 6, 14, 26)

## Core Rules & Mandates

1. **Relational Source of Truth**:
   - PostgreSQL is the sole authoritative durable business store.
   - Redis is ephemeral (cache/queues/sessions) and NOT durable truth.
   - Search engines (Meilisearch) are derived views, not source of truth.
2. **Transactional Integrity & Locking**:
   - Use atomic database transactions (`DB::transaction`) for state transitions.
   - Use row-level locking (`SELECT ... FOR UPDATE`) or optimistic concurrency to prevent race conditions and overselling.
   - Do NOT hold long-running external HTTP API calls inside database transactions.
3. **JSONB Usage Rules**:
   - Use JSONB strictly for truly unstructured metadata, custom attributes, or payload logs.
   - Never use JSONB or EAV anti-patterns to avoid proper relational modeling.
4. **Safe Migrations**:
   - Migrations belong to owning modules (`modules/<Module>/Database/Migrations/`).
   - Every migration must be reversible with a tested `down()` method.
   - Never run destructive drops on production data without a documented migration plan.
   - Add deliberate indexes on foreign keys, tenant IDs, timestamps, and search columns.

## Pre-Execution Checklist
- [ ] Are foreign key constraints and cascade rules explicitly declared?
- [ ] Are compound indexes created for common tenant and query paths?
- [ ] Is money stored as minor units (integers/bigint) or using `brick/money` patterns?
- [ ] Are external API calls kept outside `DB::transaction` blocks?

## Forbidden Shortcuts
- ❌ Treating Redis as durable business storage.
- ❌ Storing financial balances as floating point numbers.
- ❌ Omitting indexes on high-cardinality foreign keys.
- ❌ Long transactions enclosing third-party network requests.

## Validation Steps
1. Test migration rollbacks (`php artisan migrate:rollback`).
2. Verify concurrency safety with simulated race condition tests.
3. Inspect `EXPLAIN ANALYZE` on critical query paths.
