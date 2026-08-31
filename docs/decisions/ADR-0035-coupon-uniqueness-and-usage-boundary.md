# ADR-0035: Coupon Uniqueness and Usage Boundary

## Status
Accepted

## Context
Coupons provide promo code access to promotions. Usage limits (total usage, per-customer usage) must be enforced.

## Decision
1. Coupon codes are uppercase-normalized and unique per Tenant (`UNIQUE(tenant_id, code)`).
2. Coupons track `usage_limit`, `per_customer_limit`, and `times_used`.
3. In Phase 04, validation checks usage limits against current active records; final decrement occurs in future Checkout/Order phases.

## Consequences
- Case-insensitive coupon entry for customers and strict per-tenant scoping.
