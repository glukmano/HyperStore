# ADR-0068: Active Cart Uniqueness and Multi-Store Scoping Policy

## Status
Accepted

## Context
A customer or guest interacting across multiple stores, markets, and channels within a tenant must have deterministic cart resolution without race conditions creating duplicate parallel active carts.

## Decision
1. **One Active Cart per Customer/Store/Channel**:
   - An authenticated customer may have exactly one `active` cart per `(tenant_id, store_id, channel_id, user_id)` tuple.
   - Enforced at the database level in PostgreSQL using a partial unique index:
     `CREATE UNIQUE INDEX unique_active_user_cart ON carts (tenant_id, store_id, channel_id, user_id) WHERE status = 'active' AND user_id IS NOT NULL;`
2. **Guest Carts**:
   - Bound to `(tenant_id, store_id, channel_id, guest_token)`.

## Consequences
- Guaranteed single active cart per context.
- Prevention of race-condition cart duplication.
