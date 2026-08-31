# PHASE-04 — PRICING, MULTI-CURRENCY, TAXES & PROMOTIONS

**Status**: `PLANNING / ACTIVE`  
**Date**: 2026-08-31  
**Authority**: [`PROJECT_MASTER_PLAN.md`](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 6, 15, 26, 27)

---

## 1. Executive Summary & Goals

PHASE-04 establishes the commercial pricing, multi-currency conversion, tax determination, and promotion rule evaluation engine for the Hyper Commerce platform.

### Strict Scope Invariants
- **Pricing & Calculation ONLY**: No checkout, orders, payments, ledger accounting, shipping calculations, vendor commission payout execution, or tax remittance accounting.
- **Float-Free Money**: All monetary amounts are integer minor units or computed using `brick/money:^0.14.2` with explicit rounding.
- **Modular Monolith Boundaries**:
  - `modules/Pricing/`: Price books, product/variant prices, customer group/tier pricing, scheduled prices, cost/margin visibility, exchange rates, and tax calculations.
  - `modules/Promotions/`: Promotion rule engine, conditions, actions, coupons, and discount evaluation.

---

## 2. Core Architecture & Requirements

### 2.1 Money & Multi-Currency (`brick/money`)
- Minor unit integer storage (e.g. cents for USD/EUR/CHF, zero decimals for JPY, 3 decimals for KWD).
- Pluggable `ExchangeRateProviderInterface` with manual rate storage, effective timestamps, and stale rate detection.

### 2.2 Price Books & Hierarchical Resolution Precedence
1. Variant-specific Price in active scoped Price Book
2. Canonical Product Price in active scoped Price Book
3. Quantity Tier / Volume Price break
4. Customer Group Price Book override
5. Channel / Market / Store Price Book override
6. Default Tenant Base Price Book

### 2.3 Tax Calculation Engine
- Tax Classes (`Standard`, `Reduced`, `Zero`, `Exempt`, `Digital`).
- Tax Zones (Country, State/Region, Market) with priority matching.
- Tax Inclusive vs Tax Exclusive price interpretation with precise rounding.

### 2.4 Promotion Rule Engine & Coupons
- Extensible `PromotionConditionRegistry` and `PromotionActionRegistry`.
- Support for percentage discount, fixed discount (multi-currency safe), fixed product price, Buy X Get Y, and Free Shipping entitlement.
- Coupons with case-normalized code uniqueness, total & per-customer usage limits, valid date ranges, and store/market scoping.
- Deterministic stacking, priority, and exclusivity rules.

### 2.5 Security, RBAC & Audit
- Permissions: `pricing.view`, `pricing.manage`, `pricing.cost.view`, `tax.view`, `tax.manage`, `promotions.view`, `promotions.manage`, `coupons.view`, `coupons.manage`, `exchange_rates.view`, `exchange_rates.manage`.
- Sensitive cost and margin figures protected from unauthorized access.
- High-value price/tax/promotion mutations logged via `AuditManagerInterface`.

---

## 3. Definition of Done

- [ ] `brick/money:^0.14.2` installed and documented in `docs/DEPENDENCIES.md`.
- [ ] `modules/Pricing/` and `modules/Promotions/` fully implemented and registered with `ModuleKernel`.
- [ ] 12 Architectural Decision Records (`ADR-0025` through `ADR-0036`) created and indexed.
- [ ] Control Center Livewire interfaces for Pricing, Taxes, and Promotions with LTR/RTL support.
- [ ] Authenticated REST API endpoints under `/api/v1/pricing` and `/api/v1/promotions`.
- [ ] Comprehensive Pest test suite covering multi-currency, tier breaks, tax inclusive/exclusive, discount stacking, and Buy X Get Y.
- [ ] Quality gates passing (Pest, Larastan Level 8, Pint, Vite build, Composer audit, NPM audit).
- [ ] Migration rollback and re-migration verified cleanly on PostgreSQL.
