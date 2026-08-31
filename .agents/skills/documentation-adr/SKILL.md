---
name: documentation-adr
description: Enforces documentation standards, Architectural Decision Records (ADRs), RFC proposals, module documentation, and dependency registration. Use when writing documentation, creating ADRs, proposing architectural changes, or adding dependencies.
---

# Documentation Standards & Architecture Decision Records (ADR)

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 0, 6, 28, 35)

## Core Rules & Mandates

1. **Documentation is Mandatory**:
   - Features, modules, API endpoints, and dependencies are incomplete without up-to-date documentation.
2. **ADR Lifecycle**:
   - Create ADRs in `docs/decisions/` for major architectural decisions using `ADR-0000-template.md`.
   - ADR Statuses: `PROPOSED`, `ACCEPTED`, `DEPRECATED`, `SUPERSEDED`.
   - **Accepted ADRs cannot be silently contradicted.**
3. **Proposals & RFCs (Master Rule 0)**:
   - When an architectural change or modification to `PROJECT_MASTER_PLAN.md` is considered, create an RFC in `docs/proposals/` using `RFC-0000-template.md`.
   - Do NOT implement the change until explicitly approved by the platform owner.
4. **Dependency Tracking**:
   - Every newly introduced Composer or NPM package MUST be registered in `docs/DEPENDENCIES.md` with:
     `Package`, `Target Version`, `Category`, `Owning Module / Scope`, `Reason / Purpose`, `License`, `Replacement Strategy`.

## Pre-Execution Checklist
- [ ] Has an ADR been created for significant architectural decisions?
- [ ] Is `docs/DEPENDENCIES.md` updated with any newly added dependencies?
- [ ] Are module contracts documented in `docs/modules/<ModuleName>/`?

## Forbidden Shortcuts
- ❌ Silently altering architectural invariants without an approved ADR or RFC.
- ❌ Adding packages to `composer.json` or `package.json` without updating `docs/DEPENDENCIES.md`.
- ❌ Leaving broken markdown links or undocumented API endpoints.

## Validation Steps
1. Verify markdown formatting and relative link validity.
2. Check that all registered packages match declared dependencies.
3. Confirm ADR status consistency.
