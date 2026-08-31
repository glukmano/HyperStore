# ADR-0001: Modular Monolith Architecture

| Field        | Value                                |
|-------------|--------------------------------------|
| ID          | ADR-0001                             |
| Status      | Accepted                             |
| Date        | 2026-08-31                           |
| Deciders    | Project Lead, Platform Architect     |
| Phase       | PHASE-01                             |

## Context

HyperStore is a complex multi-tenant, multi-store, multi-channel commerce platform.
We needed to choose between: a Microservices architecture, a traditional Monolith, or a Modular Monolith.

## Decision

We will use a **Modular Monolith** architecture throughout the platform.

All business domains (Catalog, Commerce, Fulfillment, Marketplace, etc.) are implemented as
self-contained **modules** under the `modules/` directory. Each module:

- Has its own namespace (`Modules\<Name>\...`)
- Declares its dependencies explicitly in `module.json`
- Is registered/booted through the **project-owned Module Kernel** (see ADR-0003)
- Must never directly call code from another module without going through a declared contract
- May only communicate cross-module via Events or Service Contracts registered in Core

The `app/Core/` directory is the shared platform foundation, not a business module.

## Rationale

| Factor                  | Microservices | Monolith | Modular Monolith ✅ |
|------------------------|:---:|:---:|:---:|
| Team size (early stage) | ❌ | ✅ | ✅ |
| Deployment simplicity   | ❌ | ✅ | ✅ |
| Domain isolation        | ✅ | ❌ | ✅ |
| Cross-domain queries    | ❌ | ✅ | ✅ |
| Incremental extraction  | N/A | ❌ | ✅ |
| Testability in isolation| ✅ | ❌ | ✅ |

A Modular Monolith gives us the bounded-context discipline of microservices while retaining
the operational simplicity of a monolith. Modules can be extracted to services if needed later.

## Consequences

- All inter-module communication must go through declared contracts in `app/Core/` or Module Contracts.
- No module may reference another module's internal classes (only its public contracts).
- The production `modules/` directory must remain empty during PHASE-01.
- Each module must pass an architecture test verifying it does not leak internals.

## References

- PROJECT_MASTER_PLAN.md §Architecture
- ADR-0003 (Project-Owned Module Kernel)
