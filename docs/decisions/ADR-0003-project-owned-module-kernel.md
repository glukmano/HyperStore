# ADR-0003: Project-Owned Module Kernel (No Third-Party Module Framework)

| Field        | Value                                |
|-------------|--------------------------------------|
| ID          | ADR-0003                             |
| Status      | Accepted                             |
| Date        | 2026-08-31                           |
| Deciders    | Project Lead, Platform Architect     |
| Phase       | PHASE-01                             |

## Context

Implementing a Modular Monolith requires a mechanism to discover, register, and boot modules.
The primary third-party option is `nwidart/laravel-modules`. We also evaluated a pure-convention
approach with no formal kernel.

## Decision

We will implement a **lightweight, project-owned Module Kernel** with zero third-party dependencies.

### Core classes in `app/Core/Modular/`:

| Class / Interface | Responsibility |
|---|---|
| `ModuleInterface` | Contract every module must satisfy |
| `ModuleRegistryInterface` | Contract for the in-memory module store |
| `ModuleKernelInterface` | Contract for the kernel orchestrator |
| `ModuleManifest` (DTO) | Immutable parsed `module.json` value object |
| `ModuleServiceProvider` | Abstract base — extends Laravel's ServiceProvider |
| `ModuleRegistry` | In-memory store with topological sort (Kahn's algorithm) |
| `ModuleKernel` | Discovers `module.json` files, drives register/boot lifecycle |
| `ModuleListCommand` | `php artisan module:list` |

### Module manifest (`module.json`) schema:

```json
{
  "name": "Catalog",
  "namespace": "Modules\\Catalog",
  "provider": "Modules\\Catalog\\CatalogServiceProvider",
  "description": "Product catalog domain",
  "version": "1.0.0",
  "enabled": true,
  "dependencies": ["Core"],
  "autoload": {}
}
```

## Rationale for rejecting `nwidart/laravel-modules`

| Concern | Detail |
|---|---|
| Opinionated file structure | Conflicts with our Modular Monolith conventions |
| Heavy scaffolding | Generates boilerplate we do not want |
| Dependency risk | Third-party maintenance burden |
| Plugin/Theme conflicts | Interferes with our Plugin SDK and Theme SDK design |
| Lock-in | Hard to remove once adopted |
| Over-engineered for Phase 01 | We need ~200 LOC, not a full framework |

## Consequences

- The kernel is explicitly simple: discover → register → boot, nothing more.
- Circular dependency detection is enforced via Kahn's topological sort in `ModuleRegistry`.
- Test fixtures live in `tests/Fixtures/Modules/` and are never loaded in production.
- Future Plugin SDK and Theme SDK will integrate with this kernel, not bypass it.
- The `modules/` production directory remains empty until business modules are created.

## References

- PROJECT_MASTER_PLAN.md §Module Architecture
- ADR-0001 (Modular Monolith)
- `app/Core/Modular/` source code
