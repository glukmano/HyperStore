---
name: devops-release
description: Enforces Git branching conventions, environment segregation (Local/Dev/Staging/Prod), CI pipelines, deployment scripts, and zero-downtime migrations/rollbacks. Use when configuring CI/CD, managing Git releases, or designing deployment pipelines.
---

# DevOps, CI/CD & Release Management

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 6, 26, 34)

## Core Rules & Mandates

1. **Environment Progression**:
   - `Local` -> `Development` -> `Staging` -> `Production`.
   - Autonomous AI development operates through Development/Staging environments before Production deployment.
2. **Git & Branching Workflow**:
   - Structured flow: `task` -> `feature-branch` -> `implementation` -> `automated tests & static analysis` -> `staging verification` -> `release tag` -> `production`.
   - Never commit broken tests, untracked dependencies, or hardcoded secrets.
3. **Zero-Downtime Deployments & Migrations**:
   - Database migrations must be forward- and backward-compatible.
   - Run expanding migrations before code deployment; run contracting migrations after old instances terminate.
   - Ensure a tested rollback strategy exists for every release.
4. **Disaster Recovery & Backup Verification**:
   - Automated database backups (`spatie/laravel-backup`) triggered before releases.
   - Periodically verify backup restorability in automated staging environments.

## Pre-Execution Checklist
- [ ] Are CI checks (Pest tests, PHPStan level 8, Pint, security audits) passing?
- [ ] Are migration rollbacks verified before merge?
- [ ] Are environment variables documented in `.env.example`?

## Forbidden Shortcuts
- ❌ Committing directly to `main` without passing automated CI gates.
- ❌ Running destructive migrations without a validated backup snapshot.
- ❌ Hardcoding production secrets into configuration or source files.

## Validation Steps
1. Execute full CI pipeline locally or in runner (`pint`, `phpstan`, `pest`, `audit`).
2. Verify migration forward and rollback idempotency.
3. Test environment deployment scripts.
