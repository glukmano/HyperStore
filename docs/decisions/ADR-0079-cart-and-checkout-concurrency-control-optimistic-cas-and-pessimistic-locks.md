# ADR-0079: Cart and Checkout Concurrency Control (Optimistic CAS & Pessimistic Locks)

## Status
Accepted

## Context
Concurrent client requests (e.g. user editing cart in two tabs, or multiple payment attempts on the same checkout session) can cause race conditions and lost updates.

## Decision
1. **Cart Optimistic Concurrency**:
   - `carts` table includes an integer `version` incremented on every line mutation. Updates use Compare-And-Swap (CAS): `UPDATE carts SET version = version + 1 WHERE id = ? AND version = ?`.
2. **Checkout Pessimistic Lock for Critical Transitions**:
   - State transitions and inventory reservations acquire a database row lock (`SELECT ... FOR UPDATE`) to serialize state progression.

## Consequences
- Elimination of race conditions and double-submits.
- Safe concurrent cart modifications.
