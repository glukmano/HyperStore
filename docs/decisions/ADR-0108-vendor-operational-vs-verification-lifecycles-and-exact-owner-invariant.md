# ADR-0108: Vendor Operational vs Verification Lifecycles & Exact Owner Invariant

## Status
ACCEPTED

## Date
2026-09-03

## Context
Vendor approval and identity verification serve different business purposes. Conflating operational state with identity verification prevents legitimate operational use cases (e.g., active vendors undergoing periodic re-verification, or verified vendors administratively suspended for billing lapses). Furthermore, vendor staff management requires a deterministic ownership model.

## Decision
1. **Dual Independent State Machines**:
   - `VendorOperationalStatus`: `draft`, `pending_approval`, `active`, `suspended`, `terminated` (terminal).
   - `VendorVerificationStatus`: `unverified`, `pending`, `verified`, `rejected`, `needs_review`.
2. **Authoritative Verification History**: `vendor_verifications` persists an append-only audit trail of verification submissions and provider-neutral results without storing raw identity documents.
3. **Exactly-One Active Owner Guarantee**:
   - PostgreSQL engine partial uniqueness: `UNIQUE (tenant_id, vendor_id) WHERE role = 'owner' AND is_active = true`.
   - Creation Bootstrap: Vendor registration atomically provisions the `Vendor` record and its initial active owner in a single database transaction.
   - Owner Protection: Generic membership mutation or deletion cannot deactivate, demote, or delete an active owner.
   - Ownership Transfer: Ownership changes exclusively via an atomic `transferOwnership()` protocol.
4. **Invitation Security**: `vendor_invitations.role` is restricted to `manager` and `staff` via check constraint; invitations cannot grant owner privileges. Tokens are 32+ cryptographically random bytes stored as SHA-256 hashes.

## Consequences
- Operational and verification lifecycles evolve independently without state ambiguity.
- Exactly one active owner per vendor is guaranteed at both database and application levels.
