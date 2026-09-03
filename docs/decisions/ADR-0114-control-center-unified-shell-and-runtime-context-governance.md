# ADR-0114: Control Center Unified Shell, Dynamic Navigation, and Runtime Context Governance

## Status
Accepted

## Context
Phase-12 implements Section 12 of `PROJECT_MASTER_PLAN.md` (`CONTROL CENTER / SUPER ADMIN`). The Master Plan mandates a unified professional Control Center shell governing Super Admin, Tenant Admin, and Vendor views under the formula:
$$\text{Access / Render View} = \text{Identity} \times \text{Role} \times \text{Permission} \times \text{Context} \times \text{Plan} \times \text{Feature Flag}$$

Previously, `/control-center` had no authentication or authorization guards. Furthermore, `App\Core\Context\ContextManager` only supported Tenant, Store, Channel, Market, Locale, Currency, and User contexts, lacking a dedicated `VendorContext` for scoped marketplace views.

## Decision
1. **Unified Shell Architecture**: A single, composable Control Center shell renders views dynamically according to authenticated identity and resolved context tier (Super Admin, Tenant Admin, Store Staff, or Vendor Staff). No whole application duplication.
2. **ContextManager VendorContext Extension**: Extend `ContextManager` to include `VendorContextInterface`, providing typed `getVendor()`, `setVendor()`, and `hasVendor()` methods.
3. **Fail-Closed Context Resolution**: `ControlCenterContextMiddleware` resolves requested context (`Platform`, `Tenant`, `Store`, `Vendor`). Missing, mismatched, or unauthorized context strictly fails closed with HTTP 403 (`UnauthorizedContextException`).
4. **Tenant Status Containment**: Requests targeting a suspended or terminated tenant fail closed immediately (`TenantSuspendedException`).

## Consequences
- Clean single-shell navigation for all organizational tiers.
- Complete data isolation and zero silent fallback to default contexts.
- Vendor staff securely navigate scoped vendor features within the platform shell.
