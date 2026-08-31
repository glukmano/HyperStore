---
name: marketplace-vendors
description: Enforces Vendor lifecycle, onboarding, plans, RBAC, approval workflows, storefronts, and slug routing. Use when working on seller management, marketplace features, or vendor storefronts.
---

# Marketplace & Vendor Ecosystem

## Master Authority Reference
- **Document**: [PROJECT_MASTER_PLAN.md](file:///Volumes/Lukman/dev/Projects/HyperStore/PROJECT_MASTER_PLAN.md) (Sections 4, 11, 12)

## Core Rules & Mandates

1. **Unified Identity Model**:
   - One `User` identity can be a buyer, seller, vendor staff member, affiliate, or tenant operator.
   - Do NOT create separate authentication tables or duplicated user models.
2. **Vendor Lifecycle & Plans**:
   - Default policy: Free Vendor Plan requires manual admin approval; Paid Monthly Plan enables automatic approval (configurable).
   - Plan entitlements govern product limits, staff seats, custom domains, private suppliers, commission rates, and API access.
3. **Vendor Storefront Routing**:
   - Support three URL modes:
     1. Path-based: `https://platform.example/{vendor-slug}`
     2. Subdomain: `https://{vendor-slug}.platform.example`
     3. Custom Domain: `https://vendor-domain.example`
   - Maintain strict reserved slug validation (`admin`, `api`, `cart`, `checkout`, `orders`, `products`, `system`, etc.).
   - Ensure custom domains properly manage canonical SEO tags, redirects, and sitemaps.
4. **Vendor RBAC & Multi-Staff**:
   - Vendors can manage multi-user staff with granular permissions under their vendor account.

## Pre-Execution Checklist
- [ ] Are reserved slugs enforced upon vendor registration or slug modification?
- [ ] Are vendor plan capabilities checked via feature/entitlement gates?
- [ ] Is vendor data strictly isolated from other marketplace vendors?

## Forbidden Shortcuts
- ❌ Creating disconnected auth tables for vendors vs customers.
- ❌ Allowing vendor registration on reserved system routes.
- ❌ Permitting unapproved vendors to list active products.

## Validation Steps
1. Test vendor registration, approval flows, and plan entitlement limits.
2. Test slug conflict checks and reserved route blocks.
3. Test vendor staff RBAC permissions.
