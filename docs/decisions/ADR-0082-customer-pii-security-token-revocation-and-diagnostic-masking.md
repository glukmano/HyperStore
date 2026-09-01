# ADR-0082: Customer PII Security, Token Revocation, and Diagnostic Masking

## Status
Accepted

## Context
Checkout sessions contain Personally Identifiable Information (PII) including customer names, email addresses, phone numbers, and physical addresses.

## Decision
1. **Strict Tenant & User Scoping**:
   - Customer checkout endpoints verify authenticated `user_id == checkout.user_id` and tenant matching.
   - Guest checkouts require matching the unguessable `guest_token`.
2. **Diagnostic Masking**:
   - Control Center diagnostic logs and exception normalizers mask emails (`u***@domain.com`) and phone numbers (`***-***-1234`).
   - Address street lines are excluded from application error logs.

## Consequences
- Compliance with data privacy standards.
- Zero accidental PII leakage in application logs.
