# Architectural Decision Records

This directory contains all ADRs (Architectural Decision Records) for the HyperStore platform.

Each ADR is an immutable record of an architectural decision. To supersede an ADR, create a new one referencing the old one and update the old one's status to "Superseded by ADR-XXXX".

## Index

| ID | Title | Status | Phase |
|---|---|---|---|
| [ADR-0001](ADR-0001-modular-monolith-architecture.md) | Modular Monolith Architecture | ✅ Accepted | PHASE-01 |
| [ADR-0002](ADR-0002-postgresql-source-of-truth.md) | PostgreSQL as Primary Database Source of Truth | ✅ Accepted | PHASE-01 |
| [ADR-0003](ADR-0003-project-owned-module-kernel.md) | Project-Owned Module Kernel (No Third-Party Module Framework) | ✅ Accepted | PHASE-01 |
| [ADR-0004](ADR-0004-strict-no-float-money.md) | Strict No-Float Money — Integer Minor Units Only | ✅ Accepted | PHASE-01 |
| [ADR-0005](ADR-0005-canonical-multi-store-products.md) | Canonical Multi-Store Product Catalog | ✅ Accepted | PHASE-01 |
| [ADR-0006](ADR-0006-theme-and-plugin-isolation.md) | Theme and Plugin Isolation | ✅ Accepted | PHASE-01 |

## Template

Use [docs/templates/ADR-TEMPLATE.md](../templates/ADR-TEMPLATE.md) for new ADRs.

## Process

1. Draft the ADR in a feature branch.
2. Get review from at least one architect or senior engineer.
3. Merge — the ADR is now "Accepted".
4. ADRs are NEVER retroactively edited once accepted (except to add a "Superseded" status).
