# ADR-0046: Inventory Idempotency Persistence Strategy

## Status
Accepted

## Context
External webhooks, retried network requests, and distributed API calls must not duplicate inventory mutations (e.g. double restock, double reservation).

## Decision
1. Create `inventory_operation_keys` table:
   - `tenant_id`, `idempotency_key`, `operation_type`, `resource_type`, `resource_id`, `response_payload` (JSONB), `created_at`.
   - Unique index on `(tenant_id, idempotency_key, operation_type)`.
2. Execution Guard:
   - When an operation is called with an idempotency key, if a record exists, the original response is returned immediately without executing the underlying mutation.

## Consequences
- Guaranteed idempotent inventory operations across receiving, adjustments, reservations, and transfers.
