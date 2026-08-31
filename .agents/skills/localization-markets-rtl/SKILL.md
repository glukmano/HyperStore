---
name: localization-markets-rtl
description: Enforces dynamic language/locale management, Markets, multi-currency, timezone normalization, and strict RTL/LTR logical CSS. Use for frontend UI, translation, market configuration, or timezone handling.
---

# Localization, Markets & RTL/LTR Design

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 6, 19, 22)

## Core Rules & Mandates

1. **Dynamic Localization Architecture**:
   - Languages are installable and manageable from the Control Center.
   - Do NOT build a hardcoded two-language (e.g. Arabic/English only) system; architecture must support N languages.
   - Separate system UI translations from localized content (products, categories, CMS, emails).
2. **Strict RTL / LTR Logical CSS**:
   - All UI components must be fully bidirectional (RTL and LTR native).
   - **MANDATORY**: Use CSS logical properties and Tailwind logical utilities (`ms-*`, `me-*`, `ps-*`, `pe-*`, `start-*`, `end-*`, `text-start`, `text-end`).
   - **FORBIDDEN**: Hardcoded physical direction classes (`pl-*`, `pr-*`, `ml-*`, `mr-*`, `left-*`, `right-*`, `text-left`, `text-right`) when logical flow is intended.
3. **Markets & Multi-Currency**:
   - Markets define countries, currencies, tax rules, available payment methods, shipping zones, and catalog visibility.
   - Prices format according to active `MarketContext` and `LocaleContext`.
4. **Timezones & Timestamps**:
   - Store all database timestamps in **UTC**.
   - Convert to tenant/user/store local timezone at presentation time with explicit timezone context.

## Pre-Execution Checklist
- [ ] Are Tailwind classes purely logical (`start`/`end`, `ms`/`me`, `ps`/`pe`)?
- [ ] Is UTC used for all timestamp storage and internal calculations?
- [ ] Are translation strings keyed and managed through standard translation loaders?

## Forbidden Shortcuts
- ❌ Hardcoding physical CSS direction utilities (`left-0`, `pl-4`, `text-left`).
- ❌ Hardcoding Arabic and English as the only supported locales.
- ❌ Storing local non-UTC timestamps in the database.

## Validation Steps
1. Inspect UI rendering in both RTL (`dir="rtl"`) and LTR (`dir="ltr"`).
2. Test market-specific pricing, currency formatting, and tax computation.
3. Verify timezone conversion in orders and audit timestamps.
