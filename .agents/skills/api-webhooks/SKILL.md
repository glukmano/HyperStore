---
name: api-webhooks
description: Enforces REST API design, Sanctum auth, rate limiting, versioning, consistent error envelopes, and secure signed webhooks. Use when building API endpoints or webhook pipelines.
---

# REST API & Signed Webhooks Standard

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 3.2, 20, 26)

## Core Rules & Mandates

1. **First-Party API Architecture**:
   - Token-based authentication using **Laravel Sanctum**.
   - Strict route versioning (`/api/v1/...`).
   - Domain actions must be reusable by REST API, mobile apps, POS, Control Center, and MCP tools.
2. **Standard JSON Envelopes**:
   - Return consistent response structures:
     ```json
     {
       "success": true,
       "data": { ... },
       "meta": { "pagination": { ... } }
     }
     ```
   - Errors must return standardized error codes, human-readable messages, and structured validation feedback.
3. **Idempotency & Rate Limiting**:
   - Mutating operations (orders, payments, refunds, payouts) require `Idempotency-Key` header handling.
   - Apply granular rate limiting on public and authenticated endpoints.
4. **Signed Webhooks**:
   - Outgoing webhooks must be cryptographically signed with HMAC SHA-256 (`X-Hyper-Signature`).
   - Include timestamp headers (`X-Hyper-Timestamp`) to prevent replay attacks.
   - Implement exponential backoff retries, delivery logging, and secret rotation capabilities.

## Pre-Execution Checklist
- [ ] Are API Resources / DTOs used rather than exposing raw Eloquent models?
- [ ] Is Sanctum token ability/scope verified on endpoints?
- [ ] Are webhook signatures generated with HMAC SHA-256 and verified?

## Forbidden Shortcuts
- ❌ Returning raw Eloquent models directly from API routes.
- ❌ Unsigned outgoing webhooks or ignoring replay attack risks.
- ❌ Missing rate limits on public/auth routes.

## Validation Steps
1. Test API authentication, token expiration, and permission scopes.
2. Test idempotency handling with identical concurrent requests.
3. Verify webhook HMAC signature verification and retry mechanisms.
