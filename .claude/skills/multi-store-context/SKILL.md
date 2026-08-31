---
name: multi-store-context
description: Enforces Store, Channel, Market, and Vendor contextual scoping and canonical multi-store Catalog listings. Use when implementing store-specific catalogs, domains, pricing, or channels.
---

# Multi-Store Context & Shared Catalog Architecture

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 4, 8, 9, 20)

## Core Rules & Mandates

1. **Context Model**:
   - A Tenant owns one or more **Stores**.
   - A Store operates across **Channels** (Web, Mobile, POS, B2B) and **Markets** (Countries, Currencies, Locales).
   - Resolve `StoreContext`, `ChannelContext`, and `MarketContext` consistently.
2. **Canonical Product Reusability**:
   - A single canonical Product definition is reusable across multiple Stores.
   - Do NOT duplicate entire Product records just because an item is listed in multiple Stores.
3. **Store-Level Listing Overrides**:
   - Store listings override price, visibility, merchandising tags, channels, and presentation without duplicating core product data.
   - Warehouses and inventory allocations may be shared or assigned by store/warehouse policy.
4. **Channel & POS Awareness**:
   - Products and categories can vary in availability, pricing, or tax rules depending on the active Sales Channel.

## Pre-Execution Checklist
- [ ] Is canonical product data decoupled from store-specific pricing and visibility?
- [ ] Is the active `StoreContext` and `MarketContext` properly resolved from domain/headers?
- [ ] Are store inventory allocations clearly separated from global product specs?

## Forbidden Shortcuts
- ❌ Duplicating product catalog rows across stores.
- ❌ Hardcoding store-specific logic into canonical product models.
- ❌ Ignoring Sales Channel restrictions in search and checkout.

## Validation Steps
1. Test multi-store catalog listing overrides (e.g. Store A vs Store B pricing/visibility for the same product).
2. Verify channel filtering in POS and API endpoints.
