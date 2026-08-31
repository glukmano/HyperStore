# ADR-0041: Concurrency Safety and Oversell Protection via Deterministic Row Locking

## Status
Accepted

## Context
Concurrent checkout requests competing for the last item in stock can cause overselling if availability checks and reservations are not atomic.

## Decision
1. Pessimistic Row Locking: When reserving or deducting stock, target `stock_items` rows are locked with `SELECT ... FOR UPDATE` within a database transaction.
2. Deterministic Lock Ordering: For multi-source reservations, candidate `stock_items` are sorted ascending by primary key `id` before acquiring locks. This prevents circular lock deadlocks between concurrent multi-item transactions.
3. Availability Re-verification: Available stock is re-evaluated immediately after lock acquisition inside the transaction.

## Consequences
- Guaranteed zero overselling under high concurrency on PostgreSQL.
