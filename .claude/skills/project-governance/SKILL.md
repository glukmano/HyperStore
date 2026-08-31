---
name: project-governance
description: Enforces phase boundaries, Master Plan authority, change classification, and Definition of Done. Always load before starting any engineering work.
---

# Project Governance & Phase Gates

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 0, 1, 33, 35, 36, 38)
- **Hierarchy**: Owner Instruction > Master Plan > Accepted ADRs > Active Phase > Module Docs > Skills > Code > External Docs > Assumptions.

## Core Rules & Mandates

1. **PROJECT_MASTER_PLAN.md is Supreme**: The agent MUST NOT modify `PROJECT_MASTER_PLAN.md` unless explicitly commanded by the platform owner.
2. **Phase-Gated Development**: Work ONLY on the active phase file (e.g. `docs/phases/PHASE-01-FOUNDATION.md`).
   - No active phase file = NO feature or commerce coding.
   - Do NOT advance to the next phase automatically.
3. **Change Classification**:
   - **Class A**: Fits active phase + architecture -> Implement.
   - **Class B**: Fits phase but ambiguous -> Choose conservative compatible solution and document.
   - **Class C**: Requires architecture change -> Submit RFC to `docs/proposals/`; do not implement.
   - **Class D**: Belongs to future phase -> Do not implement.
   - **Class E**: Security-critical unresolved risk -> Halt risky component; document decision needed.
4. **Definition of Done Compliance**:
   - Tests written and 100% green.
   - Static analysis (Larastan Level 8+) clean with 0 errors.
   - Code style formatted with Laravel Pint.
   - Security, tenant isolation, and audit behaviors verified.
   - Documentation and `docs/DEPENDENCIES.md` updated.

## Pre-Execution Checklist
- [ ] Has `PROJECT_MASTER_PLAN.md` been read?
- [ ] Is there an active phase file in `docs/phases/`?
- [ ] Is the requested task within the explicit scope of the active phase?
- [ ] Are required domain skills loaded?

## Forbidden Shortcuts
- ❌ Modifying `PROJECT_MASTER_PLAN.md` without explicit owner instruction.
- ❌ Starting feature development without an active phase specification.
- ❌ Automatically beginning subsequent phases upon finishing current tasks.
- ❌ Simplifying, reducing, or removing platform capabilities to bypass complexity.

## Validation Steps
1. Verify task matches active phase scope.
2. Ensure no uncommitted architectural deviations exist.
3. Verify test suite and static analysis passes prior to stopping.
