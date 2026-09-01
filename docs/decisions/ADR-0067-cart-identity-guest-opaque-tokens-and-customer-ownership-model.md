# ADR-0067: Cart Identity, Guest Opaque Tokens, and Customer Ownership Model

## Status
Accepted

## Context
Carts can be initiated by anonymous guest visitors or authenticated customers. Exposing auto-incrementing database IDs in URLs or API headers exposes the platform to Insecure Direct Object Reference (IDOR) attacks, cart hijacking, and cross-tenant leakage.

## Decision
1. **Dual Identity Support**:
   - **Guest Carts**: Identified by a cryptographically secure 64-character hex token (`guest_token`) stored in HTTP headers or secure cookies (`X-Cart-Token`).
   - **Authenticated Carts**: Linked directly to `customer_id` / `user_id` authenticated via Sanctum.
2. **Security & Scoping**:
   - Sequential database primary keys are strictly internal and never exposed as client authorization keys.
   - All cart lookups require tenant scoping: `Cart::where('tenant_id', $tenantId)->where(...)`.
   - Guest tokens cannot access authenticated customer carts.

## Consequences
- Complete protection against IDOR vulnerabilities.
- Cryptographically sound guest-to-customer ownership model.
