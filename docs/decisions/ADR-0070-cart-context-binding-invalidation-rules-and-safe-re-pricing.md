# ADR-0070: Cart Context Binding, Invalidation Rules, and Safe Re-Pricing

## Status
Accepted

## Context
A Cart operates within a multi-store, multi-market, multi-currency environment. If the buyer changes store, market, or currency during shopping, existing cart lines and prices may become invalid or require recalculation.

## Decision
1. **Explicit Context Binding**:
   - Every Cart is strictly bound to `tenant_id`, `store_id`, `market_id`, `channel_id`, `currency`, and `locale`.
   - Never falls back to default tenant or store.
2. **Context Invalidation**:
   - Changing store or channel terminates/revalidates cart eligibility.
   - Changing currency or market triggers an explicit re-pricing pass through Pricing module contracts. Client prices are never trusted.

## Consequences
- 100% multi-tenant and multi-store consistency.
- Zero silent currency conversion errors.
