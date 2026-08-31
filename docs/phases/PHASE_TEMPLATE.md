# PHASE-XX: [Phase Name]

> **Authority**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md)  
> **Status**: [DRAFT | ACTIVE | COMPLETED]  
> **Active Dates**: YYYY-MM-DD to YYYY-MM-DD  

---

## 1. Objective

Clear, high-level statement of what this phase accomplishes.

## 2. Included Scope

- Itemized deliverables belonging strictly to this phase.
- Specific modules, services, or contracts to build.

## 3. Explicitly Excluded Scope

- Features belonging to subsequent phases.
- Out-of-bounds architectural modifications.

## 4. Required Skills

- List of project skills to activate (e.g. `project-governance`, `laravel-platform`, `postgresql-data-design`).

## 5. Prerequisites

- Previous completed phases.
- Environment or dependency prerequisites.

## 6. Architecture & ADRs

- Relevant ADRs to adhere to or create.
- Module boundaries and contracts.

## 7. Database Work

- Migrations to create.
- Tables, indexes, foreign keys, and constraints.
- Transactional and locking considerations.

## 8. Backend Work

- Domain services, actions, DTOs, value objects, events, listeners, and policies.
- Module contracts and integrations.

## 9. Frontend Work

- Blade / Livewire components.
- Theme integration via `<x-ui.*>` component abstractions.
- RTL/LTR compatibility.

## 10. API Work

- REST endpoints, API resources, requests, route definitions.
- Sanctum token scopes and rate limiting.
- Webhooks and event payloads.

## 11. Security

- Authorization policies and role permissions.
- Input validation and output escaping.
- Tenant and store data isolation invariants.

## 12. Tests

- Pest unit and feature tests.
- High-risk invariants (isolation, concurrency, money, permissions).
- Static analysis (Larastan level 8+) and Pint code formatting.

## 13. Documentation

- Module documentation updates in `docs/modules/`.
- Dependency registry updates in `docs/DEPENDENCIES.md`.
- Architectural notes in `docs/architecture/`.

## 14. Acceptance Criteria

- [ ] All unit and feature tests pass with 100% green status.
- [ ] Larastan / PHPStan level 8 passes with zero errors.
- [ ] Laravel Pint style formatting checks pass cleanly.
- [ ] Tenant and security boundaries verified.
- [ ] Documentation and dependency registry updated.

## 15. Stop Condition

When all acceptance criteria are satisfied:
1. Run all tests and linters.
2. Produce a phase completion report.
3. **STOP and wait for user instruction before beginning the next phase.**
