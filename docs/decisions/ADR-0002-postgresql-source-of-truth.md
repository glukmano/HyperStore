# ADR-0002: PostgreSQL as Primary Database Source of Truth

| Field        | Value                                |
|-------------|--------------------------------------|
| ID          | ADR-0002                             |
| Status      | Accepted                             |
| Date        | 2026-08-31                           |
| Deciders    | Project Lead, Platform Architect     |
| Phase       | PHASE-01                             |

## Context

We needed to select a primary relational database for all persistent business data:
orders, catalog, inventory, users, tenants, stores, ledger, and audit logs.

The choices evaluated were: MySQL 8, MariaDB, PostgreSQL, and SQLite (dev only).

## Decision

**PostgreSQL 14+** is the sole relational source of truth for all persistent business data.

SQLite may be used exclusively in unit tests via `RefreshDatabase`.
Redis is used for caching, queues, and sessions — never as a primary data store.

## Rationale

PostgreSQL was chosen for the following platform-critical capabilities:

| Capability                     | Reason |
|-------------------------------|--------|
| JSONB columns                 | Flexible attribute/metadata storage for Catalog |
| Row-level security (RLS)      | Future tenant isolation without schema forking |
| Partial indexes               | Essential for soft-delete performance |
| Full-text search (pg_trgm)    | Product search without Elasticsearch in early phases |
| Advisory locks                | Safe concurrent inventory/ledger updates |
| UUID primary keys             | Required for distributed ID generation |
| CHECK constraints             | Enforce money invariants at DB level |
| Transactional DDL             | Safe schema migration rollbacks |
| `FOR UPDATE SKIP LOCKED`      | Queue-safe job locking |

## Consequences

- All migrations must target PostgreSQL syntax — no cross-DB compatibility shims.
- Laravel model `$casts` must use `array` or `AsCollection` for JSONB columns.
- Money must be stored as `integer` (minor units) in `bigint` columns (see ADR-0004).
- No `enum` DB columns — use `varchar` + PHP-backed Enums for portability.
- Multi-tenant physical isolation strategy (schema-per-tenant vs shared) is deferred to Phase 02.

## References

- PROJECT_MASTER_PLAN.md §Database
- ADR-0004 (Strict No-Float Money)
- docs/DEPENDENCIES.md (PostgreSQL 14+ runtime dependency)
