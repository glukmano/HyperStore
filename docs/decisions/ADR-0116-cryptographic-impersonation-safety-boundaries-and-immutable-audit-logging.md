# ADR-0116: Audited Context Switching, Cryptographic Impersonation Safety Boundaries, and Append-Only Audit Logging

## Status
Accepted

## Context
Section 12 of `PROJECT_MASTER_PLAN.md` requires authorized context switching and impersonation to be audited. Impersonation introduces significant security risks: session hijacking, unrecorded administrative actions, and irreversible financial modifications executed under borrowed identities.

## Decision
1. **Dual-Table Separation**:
   - `impersonation_sessions`: Authoritative operational state machine (`active`, `terminated`, `revoked`, `expired`). Token hash lookup, locked via `SELECT ... FOR UPDATE` during authorization and revocation.
   - `impersonation_events`: Append-only audit history. A PostgreSQL database trigger unconditionally rejects `UPDATE` and `DELETE` queries.
2. **Cryptographic Ephemeral Tokens**: Sessions are authenticated via signed/encrypted tokens hashed with SHA-256. Database row is the ultimate authority; cache is acceleration-only and purged immediately upon revocation.
3. **Safety Boundaries**:
   - Strictly hardcoded 60-minute maximum TTL.
   - No nested impersonation (`NestedImpersonationForbiddenException`).
   - No impersonation of Super Admins (`SuperAdminImpersonationForbiddenException`).
   - Impersonated sessions are strictly blocked from executing irreversible financial operations (e.g. payout finalization) or mutating credentials (`PrivilegedActionBlockedException`).

## Consequences
- Complete, tamper-proof audit trail for all impersonated operations.
- Strong protection against privilege escalation and irreversible harm.
