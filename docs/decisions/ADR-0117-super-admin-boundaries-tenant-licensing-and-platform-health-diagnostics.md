# ADR-0117: Super Admin Boundaries, Tenant Licensing, SaaS Plans, Release Governance, and Platform Health Diagnostics

## Status
Accepted

## Context
Super Admin operates globally across all tenants. Section 12 explicitly assigns Super Admin ownership of tenants, licenses, SaaS plans, releases, official extension marketplaces, global platform settings, and platform health.

## Decision
1. **Tenant Operational Lifecycle**: Managed exclusively by `TenantLifecycleService` over `tenants.status` (`provisioning`, `active`, `suspended`, `terminated`). Suspended tenants block all child operations.
2. **Authoritative SaaS Plans**: `platform_saas_plans` aggregate owns default limits and feature entitlements. `tenant_licenses` references the plan and contains explicit tenant overrides.
3. **No Silent Grandfathering**: Plan hard-limit reductions validate current committed usage across all inheriting tenants in ascending `tenant_id` lock order; any exceeding tenant causes the mutation to fail closed.
4. **Official Extension Catalog Governance**: `official_extensions` governs extension administrative metadata (approval, visibility, compatibility). Runtime execution and sandboxed installation remain strictly in Phase 21.
5. **Platform Releases**: `platform_releases` governs release notes and SemVer compatibility administratively without deployment execution.
6. **Encrypted Platform Settings**: `platform_settings` enforces cryptographic encryption via `Crypt::encryptString()` for sensitive configuration keys.
7. **Platform Health Diagnostics**: Unified probe querying PostgreSQL, Redis, Cache, and queue health.

## Consequences
- Clear separation between platform infrastructure governance and commerce execution.
- High-integrity multi-tenant SaaS licensing and quota admission.
