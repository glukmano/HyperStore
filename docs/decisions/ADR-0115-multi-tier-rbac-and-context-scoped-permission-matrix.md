# ADR-0115: Multi-Tier RBAC Architecture and Context-Scoped Permission Matrix

## Status
Accepted

## Context
Hyper Commerce Platform operates across four hierarchical layers:
```text
Platform (Super Admin)
└── Tenant (Tenant Admin / Staff)
    └── Store (Store Admin / Staff)
    └── Vendor (Vendor Owner / Manager / Staff)
```
Permissions must not be evaluated globally without context scoping. A user may be an Admin in Tenant A, a Staff member in Store B, and an Owner in Vendor C, without cross-tier privilege escalation.

## Decision
1. **Tier Separation**:
   - Super Admin: Governed by `User.is_super_admin = true` and `EnsureSuperAdminMiddleware`.
   - Tenant Scope: Governed by `TenantUser` membership and roles (`owner`, `admin`, `staff`).
   - Store Scope: Governed by `StoreUser` membership and roles.
   - Vendor Scope: Governed by `VendorUser` membership and roles (`owner`, `manager`, `staff`).
2. **Contextual Evaluation**: Gates and policies check permissions strictly within the active resolved request context from `ContextManager`.
3. **No Cross-Tenant Escalation**: Membership in one tenant grants zero rights in foreign tenants.

## Consequences
- Clean, uncompromised role boundaries.
- Full defense against IDOR vulnerabilities.
