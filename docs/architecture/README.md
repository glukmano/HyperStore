# Architecture Documentation

This directory contains high-level architecture designs, system topologies, context models, and cross-cutting design documents for the **Hyper Commerce Platform**.

## Key References

- [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) — Master Architecture Baseline & Governance Contract
- [Architectural Decision Records (ADRs)](file:///Volumes/Lukman/dev/Projects/HyperStore/docs/decisions/README.md)
- [Proposals / RFCs](file:///Volumes/Lukman/dev/Projects/HyperStore/docs/proposals/README.md)
- [Module Specifications](file:///Volumes/Lukman/dev/Projects/HyperStore/docs/modules/README.md)

## Core Architectural Invariants

1. **Modular Monolith First**: Strict module boundaries with public contracts.
2. **Context Hierarchy**: Platform -> Tenant -> Store -> Channel / Market / Vendor.
3. **Multi-Store Shared Catalog**: Canonical products reusable across multiple stores.
4. **Relational Source of Truth**: PostgreSQL transactional durability; no business truth in Redis or Search indices.
5. **Strict Financial Invariants**: Zero-sum double-entry ledger, minor currency units, no floating-point arithmetic.
