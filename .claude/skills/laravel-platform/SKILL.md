---
name: laravel-platform
description: Enforces Laravel 13, PHP 8.4+, thin controllers, Livewire boundaries, and code quality standards. Use for all Laravel backend and Livewire component development.
---

# Laravel Platform & Modern PHP Standards

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 6, 22, 26)

## Core Rules & Mandates

1. **Approved Stack Baseline**:
   - PHP 8.4+ (strict typing, match expressions, property hooks, readonly classes).
   - Laravel 13 standard idioms and conventions.
   - Blade & Livewire 4 for reactive server-rendered UI.
   - Tailwind CSS 4 & daisyUI 5 via reusable UI components.
2. **Thin Controllers & Actions**:
   - Controllers and Livewire components must be thin presentation adapters.
   - Livewire is NOT the business domain layer.
   - Encapsulate business logic in Action classes, Domain Services, and Value Objects.
3. **Model Hygiene**:
   - Avoid giant models holding entire business operations.
   - Use dedicated scopes, casts, query builders, and domain actions.
4. **Code Style & Static Analysis**:
   - Format all code with **Laravel Pint**.
   - Ensure **Larastan / PHPStan Level 8+** passes with zero errors.

## Pre-Execution Checklist
- [ ] Are strict types enabled (`declare(strict_types=1);`)?
- [ ] Is business logic separated from Livewire components and controllers?
- [ ] Are form requests or strongly-typed DTOs used for input validation?

## Forbidden Shortcuts
- ❌ Putting business/ledger/order calculation logic in Livewire components.
- ❌ Massive `God Models` containing hundreds of unrelated methods.
- ❌ Introducing unapproved frontend frameworks (e.g. React/Vue for core storefront).
- ❌ Bypassing static analysis warnings or disabling strict type checks.

## Validation Steps
1. Run `vendor/bin/pint --test` for styling compliance.
2. Run `vendor/bin/phpstan analyse` / `vendor/bin/larastan` (Level 8+).
3. Run `vendor/bin/pest` to verify component and unit behavior.
