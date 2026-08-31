---
name: architecture-boundaries
description: Enforces Modular Monolith architecture, Core/Module/Plugin boundaries, and service contracts. Use when creating modules, services, or cross-module integrations.
---

# Architecture Boundaries & Modular Monolith

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 3.1, 3.2, 3.3, 7, 21)

## Core Rules & Mandates

1. **Modular Monolith First**:
   - Maintain a single deployable monolith with strict internal module encapsulation.
   - Do NOT introduce microservices at the start.
   - Structure domain features into `modules/<ModuleName>/`.
2. **Strict Module Contracts**:
   - Modules interact solely via public Contracts/Interfaces, Data Transfer Objects (DTOs), and Events.
   - Never directly query another module's internal Eloquent models or internal tables.
3. **Core Isolation**:
   - `app/Core/` provides foundational lifecycle, kernel, and context services.
   - Core does NOT depend on specific business modules.
4. **Plugin Boundaries**:
   - Plugins reside in `plugins/` and integrate via hook points, registries, and contracts.
   - Plugins MUST NEVER edit Core or module source code directly.
5. **No Scattered Conditionals**:
   - Avoid scattered `if ($provider == ...)` or `if ($type == ...)` logic. Use strategy/driver registries.

## Pre-Execution Checklist
- [ ] Are dependencies between modules modeled as interfaces or DTOs?
- [ ] Is direct database leakage across module boundaries prevented?
- [ ] Are extensibility points registered via registries or service providers?

## Forbidden Shortcuts
- ❌ Direct foreign key queries bypassing module service contracts.
- ❌ Splitting into microservices prematurely.
- ❌ Hardcoding provider-specific logic directly in core pipelines.
- ❌ Modifying core files from plugins or themes.

## Validation Steps
1. Run architecture tests asserting boundary separation.
2. Verify module service providers register public bindings correctly.
