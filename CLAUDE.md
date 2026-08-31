# Hyper Commerce Platform — Claude Code Instructions

> **Authority**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) is the highest-authority technical document in this repository.

## Core Directives

1. **PROJECT_MASTER_PLAN.md is authoritative**: Every coding session must respect the master plan. Never modify it unless the owner explicitly instructs you to do so.
2. **Load `project-governance` skill**: Always consult `.claude/skills/project-governance/SKILL.md` before designing or engineering.
3. **Phase-Gated Development**: Work ONLY on the active phase file in `docs/phases/PHASE-*.md`. If no active phase file exists, do not implement commerce or business features.
4. **Never advance phases automatically**: When an active phase is complete, run all validation checks, document results, and stop.
5. **Never change architectural invariants without approval**: For proposed architectural modifications, create an RFC in `docs/proposals/` and wait for explicit owner approval.
6. **Strict Architectural Rules**:
   - Modular Monolith architecture; Core/Module/Plugin separation.
   - Never use binary floating-point types for money (use minor units / `brick/money`).
   - PostgreSQL is the relational source of truth; Redis and Search are secondary.
   - Thin controllers; Livewire is a UI component layer, not the domain layer.
   - Do not choose unresolved architectural decisions (e.g. physical SaaS multi-tenancy model) prematurely.
7. **Quality & Definition of Done**:
   - Write automated tests (Pest) covering unit, feature, isolation, and security regressions.
   - Enforce static analysis (Larastan level 8+) and code style (Laravel Pint).
   - Maintain documentation in `docs/` and register all dependencies in `docs/DEPENDENCIES.md`.
