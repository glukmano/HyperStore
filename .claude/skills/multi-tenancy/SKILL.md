---
name: multi-tenancy
description: Enforces TenantContext, data isolation, tenant authorization, and cross-tenant leakage prevention. Use for all tenant-scoped data and logic.
---

# Multi-Tenancy & Tenant Data Isolation

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 4, 8, 25, 26, 27)

## Core Rules & Mandates

1. **Context Hierarchy**:
   - Platform -> Tenant -> Store -> Channel / Market / Vendor.
   - All tenant resources must resolve under an explicit `TenantContext`.
2. **Strict Data Isolation**:
   - Every tenant-owned query must be explicitly scoped by `tenant_id` / `TenantContext`.
   - Never rely solely on accidental global scopes for multi-tenant security.
3. **Physical Tenancy Strategy Unchosen**:
   - The physical multi-tenancy storage model (shared DB, schema-per-tenant, database-per-tenant, hybrid) is deferred to an explicit future ADR.
   - Do NOT hardcode assumptions that force a specific physical tenancy architecture now.
4. **Mandatory Isolation Testing**:
   - Cross-tenant penetration and isolation tests are mandatory for all tenant-aware features.
   - Verify that Tenant A can never view, mutate, or access Tenant B resources.

## Pre-Execution Checklist
- [ ] Is `TenantContext` properly resolved on requests, jobs, and commands?
- [ ] Are query builders and repository methods explicitly filtered by tenant?
- [ ] Are route model bindings checking tenant ownership?

## Forbidden Shortcuts
- ❌ Relying solely on Eloquent global scopes without policy authorization.
- ❌ Hardcoding physical database-per-tenant or schema-per-tenant strategies before approved ADR.
- ❌ Skipping cross-tenant data leak tests.

## Validation Steps
1. Execute automated cross-tenant security test suites.
2. Assert 403 Forbidden or 404 Not Found when requesting resources from a foreign tenant context.
